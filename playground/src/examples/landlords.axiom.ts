import propertyTypeConfigCSV from './property_type_config.csv?raw';
import { parseCSV } from '../utils/csv';

export const LANDLORDS_EXAMPLE = `// Landlords Property Owners
// Demonstrates nested exposures — each property is rated independently
// then aggregated with a multi-property discount

// --- Input types ---

type Property = {
    address: string,
    property_type: string,
    buildings_sum_insured: number,
    contents_sum_insured: number,
    annual_rent: number,
    year_built: number,
    listed_type: string,
    number_of_units: number,
    flood_risk: number,
    subsidence_risk: number,
}

type LandlordExposure = {
    number_of_employees: number,
    turnover: number,
    is_portfolio: bool,
    properties: list(Property),
}

type ClaimsHistory = {
    number_of_claims: number,
    total_claims_value: number,
}

// --- Outcome types ---

type PropertyBreakdown = {
    address: string,
    buildings_premium: number,
    contents_premium: number,
    rent_premium: number,
    pol_premium: number,
    property_total: number,
}

type ProductOutcome =
    offered {
        property_details: list(PropertyBreakdown),
        property_subtotal: number,
        discount_rate: number,
        property_net: number,
        el_premium: number,
        legal_premium: number,
        total_gross: number,
        total_net: number,
        commission_rate: number,
        currency: string,
    }
  | declined { reasons: list(string) }

// --- Property type configuration (from property_type_config.csv) ---
// Rates and factors vary by property type: residential, commercial, HMO, mixed-use

table property_type_config: list({
    property_type: string,
    buildings_rate: number,
    contents_rate: number,
    pol_base: number,
    flood_factor: number,
    subsidence_factor: number,
})

namespace PropertyConfig {
    BuildingsRate(property_type: string): number {
        match row in property_type_config { row.property_type == property_type => row.buildings_rate, _ => 0.04 }
    }
    ContentsRate(property_type: string): number {
        match row in property_type_config { row.property_type == property_type => row.contents_rate, _ => 0.05 }
    }
    POLBase(property_type: string): number {
        match row in property_type_config { row.property_type == property_type => row.pol_base, _ => 50 }
    }
    FloodFactor(property_type: string): number {
        match row in property_type_config { row.property_type == property_type => row.flood_factor, _ => 0.15 }
    }
    SubsidenceFactor(property_type: string): number {
        match row in property_type_config { row.property_type == property_type => row.subsidence_factor, _ => 0.10 }
    }
}

// --- Per-property rating ---
// Each property is rated independently — buildings, contents, rent, property owners liability.
// Loadings are applied based on property characteristics (age, listed status, flood/subsidence risk).

namespace PropertyRating {
    // Listed building loading
    ListedLoading(listed_type: string): number {
        match listed_type {
            "not_listed" => 0,
            "grade_2" => 0.35,
            "grade_1" => 0.75,
            _ => 0,
        }
    }

    // Building age loading — older properties attract higher rates
    AgeLoading(year_built: number): number {
        match year_built {
            [0..1900] => 0.25,
            [1901..1945) => 0.15,
            [1945..1980) => 0.05,
            [1980..9999] => 0,
            _ => 0,
        }
    }

    // Flood risk loading — scaled by property type flood factor
    FloodRiskLoading(flood_risk: number, property_type: string): number {
        if flood_risk <= 3 then 0
        else if flood_risk <= 6 then PropertyConfig.FloodFactor(property_type) * 0.5
        else PropertyConfig.FloodFactor(property_type)
    }

    // Subsidence risk loading
    SubsidenceRiskLoading(subsidence_risk: number, property_type: string): number {
        if subsidence_risk <= 3 then 0
        else if subsidence_risk <= 6 then PropertyConfig.SubsidenceFactor(property_type) * 0.5
        else PropertyConfig.SubsidenceFactor(property_type)
    }

    // Combined property loadings
    TotalLoadings(prop: Property): number {
        ListedLoading(prop.listed_type)
        + AgeLoading(prop.year_built)
        + FloodRiskLoading(prop.flood_risk, prop.property_type)
        + SubsidenceRiskLoading(prop.subsidence_risk, prop.property_type)
    }

    // Buildings section premium
    BuildingsPremium(prop: Property): number {
        (prop.buildings_sum_insured / 1000) * PropertyConfig.BuildingsRate(prop.property_type) * (1 + TotalLoadings(prop))
    }

    // Contents section premium
    ContentsPremium(prop: Property): number {
        (prop.contents_sum_insured / 1000) * PropertyConfig.ContentsRate(prop.property_type) * (1 + TotalLoadings(prop))
    }

    // Rent receivable premium
    RentPremium(prop: Property): number {
        if prop.annual_rent == 0 then 0
        else (prop.annual_rent / 1000) * 0.025 * (1 + TotalLoadings(prop))
    }

    // Property owners liability — per property, scaled by number of units
    POLPremium(prop: Property): number {
        PropertyConfig.POLBase(prop.property_type) * (1 + units_loading)
        where units_loading = if prop.number_of_units > 4 then 0.25
                              else if prop.number_of_units > 2 then 0.10
                              else 0
    }

    // Total premium for a single property
    Total(prop: Property): number {
        BuildingsPremium(prop) + ContentsPremium(prop) + RentPremium(prop) + POLPremium(prop)
    }

    // Per-property breakdown with rounded values
    Breakdown(prop: Property): PropertyBreakdown {
        {
            address: prop.address,
            buildings_premium: round(BuildingsPremium(prop), 2),
            contents_premium: round(ContentsPremium(prop), 2),
            rent_premium: round(RentPremium(prop), 2),
            pol_premium: round(POLPremium(prop), 2),
            property_total: round(Total(prop), 2),
        }
    }
}

// --- Multi-property discount ---
// Portfolio customers (via broker arrangement) get enhanced discounts

MultiPropertyDiscount(num_properties: number, is_portfolio: bool): number {
    if is_portfolio then portfolio_discount
    else standard_discount
    where standard_discount = match num_properties {
        1 => 0,
        2 => 0.05,
        3 => 0.075,
        [4..6] => 0.10,
        [7..10] => 0.125,
        [11..99] => 0.15,
        _ => 0,
    },
    portfolio_discount = match num_properties {
        [1..3] => 0.10,
        [4..6] => 0.15,
        [7..10] => 0.175,
        [11..99] => 0.20,
        _ => 0,
    }
}

// --- Employers Liability (landlord-level, not per-property) ---

namespace EmployersLiability {
    BaseRate(number_of_employees: number): number {
        match number_of_employees {
            0 => 0,
            [1..5] => 0.008,
            [6..10] => 0.007,
            [11..25] => 0.0065,
            [26..99] => 0.006,
            _ => 0.008,
        }
    }

    Rate(turnover: number, number_of_employees: number): number {
        if number_of_employees == 0 then 0
        else round((turnover / 100) * BaseRate(number_of_employees), 2)
    }
}

// --- Legal Expenses (flat rate by portfolio size) ---

LegalExpensesPremium(num_properties: number): number {
    match num_properties {
        [1..3] => 95,
        [4..6] => 150,
        [7..10] => 225,
        [11..99] => 350,
        _ => 95,
    }
}

// --- Claims loading (applied to property subtotal) ---

ClaimsLoading(claims: ClaimsHistory): number {
    match claims.number_of_claims {
        0 => -0.10,
        1 => 0,
        2 => 0.15,
        3 => 0.35,
        4 => 0.60,
        [5..99] => 1.00,
        _ => 0,
    }
}

// --- Worst-case lookups across all properties ---
// Useful for underwriting rules that check the riskiest property

TotalBuildingsSI(properties: list(Property)): number {
    sum collect prop in properties => prop.buildings_sum_insured
}

// --- Product entry point ---
// Validates exposure, rates each property, aggregates with discount,
// adds landlord-level covers (EL, legal expenses)

Product(exposure: LandlordExposure, claims: ClaimsHistory): ProductOutcome {
    if len(exposure.properties) == 0
        then declined { reasons: ["At least one property is required"] }
    else if len(exposure.properties) > 25
        then declined { reasons: ["Maximum 25 properties per policy"] }
    else if claims.number_of_claims > 5
        then declined { reasons: ["Too many claims — manual review required"] }
    else if TotalBuildingsSI(exposure.properties) > 10000000
        then declined { reasons: ["Total buildings sum insured exceeds 10M limit"] }
    else offered {
        property_details: collect prop in exposure.properties => PropertyRating.Breakdown(prop),
        property_subtotal: round(prop_subtotal, 2),
        discount_rate: disc_rate,
        property_net: round(prop_net, 2),
        el_premium: el_prem,
        legal_premium: legal_prem,
        total_gross: round(total, 2),
        total_net: round(total * (1 - 0.30), 2),
        commission_rate: 0.30,
        currency: "GBP",
    }
    where claims_load = ClaimsLoading(claims),
          raw_property_total = sum collect prop in exposure.properties => PropertyRating.Total(prop),
          prop_subtotal = raw_property_total * (1 + claims_load),
          disc_rate = MultiPropertyDiscount(len(exposure.properties), exposure.is_portfolio),
          prop_net = prop_subtotal * (1 - disc_rate),
          el_prem = EmployersLiability.Rate(exposure.turnover, exposure.number_of_employees),
          legal_prem = LegalExpensesPremium(len(exposure.properties)),
          total = prop_net + el_prem + legal_prem
}
`;

// Scenario: Small landlord with 3 properties
// - Residential flat in London (1960s build, 1 unit)
// - Commercial unit in Manchester (2005 build, 1 unit)
// - HMO in Bristol (Victorian, 6 units, grade 2 listed, higher flood risk)
export const LANDLORDS_INPUT = {
  exposure: {
    number_of_employees: 2,
    turnover: 50000,
    is_portfolio: false,
    properties: [
      {
        address: "Flat 4, 23 Camden Road, London NW1",
        property_type: "residential",
        buildings_sum_insured: 250000,
        contents_sum_insured: 15000,
        annual_rent: 14400,
        year_built: 1962,
        listed_type: "not_listed",
        number_of_units: 1,
        flood_risk: 2,
        subsidence_risk: 3,
      },
      {
        address: "Unit 7, Enterprise Park, Manchester M4",
        property_type: "commercial",
        buildings_sum_insured: 400000,
        contents_sum_insured: 30000,
        annual_rent: 28000,
        year_built: 2005,
        listed_type: "not_listed",
        number_of_units: 1,
        flood_risk: 4,
        subsidence_risk: 2,
      },
      {
        address: "12 Clifton Gardens, Bristol BS8",
        property_type: "hmo",
        buildings_sum_insured: 350000,
        contents_sum_insured: 20000,
        annual_rent: 42000,
        year_built: 1885,
        listed_type: "grade_2",
        number_of_units: 6,
        flood_risk: 7,
        subsidence_risk: 5,
      },
    ],
  },
  claims: {
    number_of_claims: 0,
    total_claims_value: 0,
  },
  _tables: {
    property_type_config: parseCSV(propertyTypeConfigCSV),
  },
};
