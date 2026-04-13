import industryConfigCSV from './industry_config.csv?raw';
import { parseCSV } from '../utils/csv';

export const HOSPITALITY_EXAMPLE = `// AIG Hospitality V2
// Translated from Abacus ProductSchemeBuilder + 6 CoverSchemeBuilders
// Subset: 4 industries, simplified BC (no alarm/construction rules)

// --- Input record types ---

type Exposure = {
    industry: string,
    number_of_employees: number,
    turnover: number,
    is_sole_trader: bool,
    years_experience: number,
}

type ClaimsHistory = {
    number_of_claims: number,
    total_claims_value: number,
}

type RiskScores = {
    flood_risk: number,
    theft_risk: number,
    terrorism_risk: number,
}

type BCConfig = {
    buildings_limit: number,
    contents_limit: number,
    stock_limit: number,
    listed_type: string,
    has_outdoor_play: bool,
    has_functions: bool,
    number_of_beds: string,
    has_heavy_deep_frying: bool,
}

type BIConfig = {
    basis_of_cover: string,
    basis_of_cover_limit: number,
    indemnity_months: number,
    rent_receivable_limit: number,
    loss_of_licence_limit: number,
}

// --- Outcome types ---

type CoverOutcome =
    rated {
        key: string,
        name: string,
        base_premium: number,
        limit: number,
        excess: number,
    }
  | not_available { reason: string }

type ProductOutcome =
    offered {
        covers: list(CoverOutcome),
        subtotal: number,
        minimum_premium: number,
        total_gross_premium: number,
        total_net_premium: number,
        commission_rate: number,
        currency: string,
    }
  | declined { reasons: list(string) }
  | referred { reasons: list(string) }

type MultiIndustrySummary = {
    pl_classes: list(string),
    el_classes: list(string),
    deep_frying_rates: list(number),
    minimum_premiums: list(number),
}

// --- Industry configuration (from industry_config.csv, 28 columns x 8 industries) ---
// Table declaration — typed companion data, loaded from CSV artifact at deploy time

table industry_config: list({
    code: string,
    buildings_fire_class: string,
    contents_fire_class: string,
    contents_theft_class: string,
    stock_theft_class: string,
    bi_fire_class: string,
    pl_class: string,
    pl_severity: number,
    el_class: string,
    el_severity: number,
    flood_banding: string,
    loss_of_licence_class: string,
    deep_frying_rate: number,
    min_premium_claims_free: number,
    min_premium_default: number,
})

// Each lookup is a pure expression that searches the table
namespace Industry {
    BuildingsFireClass(industry: string): string {
        match row in industry_config { row.code == industry => row.buildings_fire_class, _ => "" }
    }
    ContentsFireClass(industry: string): string {
        match row in industry_config { row.code == industry => row.contents_fire_class, _ => "" }
    }
    ContentsTheftClass(industry: string): string {
        match row in industry_config { row.code == industry => row.contents_theft_class, _ => "" }
    }
    StockTheftClass(industry: string): string {
        match row in industry_config { row.code == industry => row.stock_theft_class, _ => "" }
    }
    BIFireClass(industry: string): string {
        match row in industry_config { row.code == industry => row.bi_fire_class, _ => "" }
    }
    PLClass(industry: string): string {
        match row in industry_config { row.code == industry => row.pl_class, _ => "" }
    }
    ELClass(industry: string): string {
        match row in industry_config { row.code == industry => row.el_class, _ => "" }
    }
    FloodBanding(industry: string): string {
        match row in industry_config { row.code == industry => row.flood_banding, _ => "" }
    }
    LossOfLicenceClass(industry: string): string {
        match row in industry_config { row.code == industry => row.loss_of_licence_class, _ => "" }
    }
    DeepFryingRate(industry: string): number {
        match row in industry_config { row.code == industry => row.deep_frying_rate, _ => 0 }
    }
    MinPremiumClaimsFree(industry: string): number {
        match row in industry_config { row.code == industry => row.min_premium_claims_free, _ => 0 }
    }
    MinPremiumDefault(industry: string): number {
        match row in industry_config { row.code == industry => row.min_premium_default, _ => 0 }
    }

    // --- Multi-industry lookups ---
    // Core v1 examples use collection queries directly rather than max/min aggregates.
}

// --- Claims loading system (product-level, shared across all covers) ---

namespace Claims {
    // Years trading coefficient and loading (from years_trading_coefficient_and_loads.csv)
    // Years of experience are capped at 5.
    YearsTradingLoading(is_sole_trader: bool, years_experience: number): number {
        match (is_sole_trader, capped_years) {
            (false, [0..1]) => 1,
            (false, 2) => 0.5,
            (false, 3) => 0.25,
            (false, 4) => 0,
            (false, 5) => -0.25,
            (true, [0..2]) => 1,
            (true, 3) => 0.25,
            (true, 4) => 0,
            (true, 5) => -0.25,
            _ => 0,
        }
        where capped_years = if years_experience > 5 then 5 else years_experience
    }

    // Coefficient letter determines which column to use in claims cross-lookup
    Coefficient(is_sole_trader: bool, years_experience: number): string {
        match (is_sole_trader, capped_years) {
            (false, [0..1]) | (true, [0..1]) => "A",
            (false, 2) | (true, 2) => "B",
            (false, 3) | (true, 3) => "C",
            (false, 4) | (true, 4) => "D",
            (false, 5) | (true, 5) => "E",
            _ => "A",
        }
        where capped_years = if years_experience > 5 then 5 else years_experience
    }

    // Claims x years-trading cross-lookup (from claims_years_trading_loadings.csv)
    // Row = number_of_claims, column = coefficient letter
    ClaimsYearsTradingLoading(number_of_claims: number, coefficient: string): number {
        match (number_of_claims, coefficient) {
            (0, "A") | (0, "B") => 0,
            (0, "C") => -0.1,
            (0, "D") | (0, "E") => -0.2,
            (1, "A") => 1,
            (1, "B") => 0.1,
            (1, "C") => 0.1,
            (1, "D") | (1, "E") => 0.05,
            (2, "A") => 5,
            (2, "B") => 0.675,
            (2, "C") => 0.2,
            (2, "D") | (2, "E") => 0.125,
            (3, "A") => 6,
            (3, "B") => 1.75,
            (3, "C") => 0.675,
            (3, "D") | (3, "E") => 0.3,
            (4, "A") | (4, "B") => 10,
            (4, "C") => 1.75,
            (4, "D") => 0.68,
            (4, "E") => 0.675,
            (5, "A") | (5, "B") => 25,
            (5, "C") => 10,
            (5, "D") | (5, "E") => 1.75,
            _ => 100,
        }
    }

    // Claims value loading (from claims_value_loadings.csv, range-based)
    ClaimsValueLoading(total_claims_value: number): number {
        match total_claims_value {
            [0..400) => 0,
            [400..600) => 0.1,
            [600..700) => 0.2,
            [700..800) => 0.3,
            [800..900) => 0.4,
            [900..1000) => 0.5,
            [1000..2000) => 0.75,
            [2000..3000) => 1.25,
            [3000..4000) => 2,
            [4000..5000) => 3.25,
            [5000..6000) => 5.25,
            [6000..8000) => 8.5,
            [8000..9000) => 9.5,
            [9000..10000) => 10,
            [10000..) => 20,
            _ => 0,
        }
    }

    // Total claims loading: sum of all three components
    TotalLoading(exposure: Exposure, claims: ClaimsHistory): number {
        YearsTradingLoading(exposure.is_sole_trader, exposure.years_experience)
        + ClaimsYearsTradingLoading(claims.number_of_claims, coefficient)
        + ClaimsValueLoading(claims.total_claims_value)
        where coefficient = Coefficient(exposure.is_sole_trader, exposure.years_experience)
    }
}

// --- Minimum premium logic ---

MinimumPremium(exposure: Exposure, claims: ClaimsHistory, bc: BCConfig): number {
    if exposure.years_experience >= 2 && claims.number_of_claims == 0 && not bc.has_heavy_deep_frying
        then Industry.MinPremiumClaimsFree(exposure.industry)
        else Industry.MinPremiumDefault(exposure.industry)
}

// --- Public Liability (PL) — mandatory ---

namespace PublicLiability {
    // Base rate by PL classification (from base_rates.csv)
    BaseRate(classification: string): number {
        match classification {
            "A" | "B" => 0.01,
            "C" => 0.0175,
            _ => 0.01,
        }
    }

    // Turnover size discount (from discounts.csv)
    TurnoverDiscount(turnover: number): number {
        match turnover {
            [0..100000) => 0,
            [100000..150000) => -0.05,
            [150000..200000) => -0.075,
            [200000..250000) => -0.1,
            _ => 0,
        }
    }

    // Limit loading (from loadings.csv)
    LimitLoading(limit: number): number {
        match limit {
            1000000 => -0.1,
            2000000 => 0,
            5000000 => 0.25,
            _ => 0,
        }
    }

    Rate(exposure: Exposure, limit: number, total_claims_loading: number): CoverOutcome {
        rated {
            key: "PL",
            name: "Public and products liability",
            base_premium: (exposure.turnover / 100) * (base_rate * (1 + total_loads)),
            limit: limit,
            excess: 250,
        }
        where classification = Industry.PLClass(exposure.industry),
              base_rate = BaseRate(classification),
              total_loads = LimitLoading(limit) + total_claims_loading + TurnoverDiscount(exposure.turnover)
    }
}

// --- Buildings, Contents and Stock (BC) — mandatory ---

namespace BuildingsContentsStock {
    // Buildings rate by fire class (from buildingsRates.csv, 25 classes A-Y)
    BuildingsRate(classification: string): number {
        match classification {
            "B" => 0.15,
            "Y" => 0.21,
            _ => 0.1,
        }
    }

    // Material damage (contents fire) rate (from materialDamageRates.csv)
    MaterialDamageRate(classification: string): number {
        match classification {
            "B" => 0.0658,
            "Y" => 0.15,
            _ => 0.07,
        }
    }

    // Contents theft rate (from contentsAndStockTheftRates.csv)
    ContentsTheftRate(classification: string): number {
        match classification {
            "B" => 0.05625,
            "F" => 0.06066,
            _ => 0.06,
        }
    }

    // Stock theft rate (from contentsAndStockTheftRates.csv)
    StockTheftRate(classification: string): number {
        match classification {
            "C" => 0.065,
            "V" => 0.5,
            _ => 0.08,
        }
    }

    // Theft area rate adjustment by risk score (from contentsAndStockTheftAreaRates.csv)
    TheftAreaRate(theft_risk: number): number {
        match theft_risk {
            1 => 0.25, 2 => 0.2, 3 => 0.15, 4 => 0.1,
            5 | 6 => 0,
            7 => -0.1, 8 => -0.15, 9 => -0.2, 10 => -0.25,
            _ => 0,
        }
    }

    // Buildings size discount (from buildingsSizeDiscounts.csv)
    BuildingsSizeDiscount(sum_insured: number): number {
        match sum_insured {
            0 => 0,
            [1..125000) => -0.025,
            [125000..250000) => -0.05,
            [250000..375000) => -0.0625,
            [375000..500000) => -0.075,
            [500000..625000) => -0.0875,
            [625000..750000) => -0.1,
            [750000..875000) => -0.125,
            [875000..) => -0.15,
            _ => 0,
        }
    }

    // Contents and stock size discount (from contentsAndStockSizeDiscounts.csv)
    ContentsSizeDiscount(sum_insured: number): number {
        match sum_insured {
            0 => 0,
            [1..125000) => -0.025,
            [125000..250000) => -0.05,
            [250000..375000) => -0.0625,
            [375000..500000) => -0.075,
            [500000..625000) => -0.0875,
            [625000..750000) => -0.1,
            [750000..875000) => -0.125,
            [875000..) => -0.15,
            _ => 0,
        }
    }

    // Flood rate adjustment (from floodTable.csv, by risk score and banding)
    FloodAdjustment(flood_risk: number, flood_banding: string): number {
        match flood_risk {
            7 | 8 => 0.1,
            _ => 0,
        }
    }

    // Listed building loading (from listedBuildingTable.csv)
    ListedBuildingLoading(listed_type: string): number {
        match listed_type {
            "notListed" => 0,
            "grade2Listed" | "gradeB2Listed" | "gradeCsListed" => 0.4,
            _ => 0,
        }
    }

    // Facility type loading (from facilitiesTable.csv, summed for selected types)
    FacilityLoading(has_outdoor_play: bool, has_functions: bool): number {
        (if has_outdoor_play then 1 else 0)
        + (if has_functions then 0.25 else 0)
    }

    // Number of beds discount (from numberOfBedsTable.csv)
    BedsDiscount(number_of_beds: string): number {
        match number_of_beds {
            "upTo5" => -0.05,
            "upTo10" => 0,
            "upTo20" => 1,
            _ => 0,
        }
    }

    // Premises factor: combined loading from building characteristics
    PremisesFactor(
        listed_type: string,
        flood_risk: number,
        flood_banding: string,
        has_outdoor_play: bool,
        has_functions: bool,
        number_of_beds: string,
    ): number {
        ListedBuildingLoading(listed_type)
        + FloodAdjustment(flood_risk, flood_banding)
        + FacilityLoading(has_outdoor_play, has_functions)
        + BedsDiscount(number_of_beds)
    }

    // Buildings section premium
    BuildingsPremium(
        sum_insured: number,
        classification: string,
        premises_factor: number,
        total_claims_loading: number,
    ): number {
        (sum_insured / 100) * (rate * (1 + total_loads))
        where rate = BuildingsRate(classification),
              total_loads = premises_factor
                  + BuildingsSizeDiscount(sum_insured)
                  + total_claims_loading
    }

    // Contents section premium
    ContentsPremium(
        sum_insured: number,
        fire_class: string,
        theft_class: string,
        theft_risk: number,
        premises_factor: number,
        total_claims_loading: number,
    ): number {
        (sum_insured / 100) * (rate * (1 + total_loads))
        where rate = MaterialDamageRate(classification: fire_class) + ContentsTheftRate(classification: theft_class),
              total_loads = premises_factor
                  + ContentsSizeDiscount(sum_insured)
                  + TheftAreaRate(theft_risk)
                  + total_claims_loading
    }

    // Stock section premium
    StockPremium(
        sum_insured: number,
        fire_class: string,
        stock_theft_class: string,
        theft_risk: number,
        premises_factor: number,
        total_claims_loading: number,
    ): number {
        (sum_insured / 100) * (rate * (1 + total_loads))
        where rate = MaterialDamageRate(classification: fire_class) + StockTheftRate(classification: stock_theft_class),
              total_loads = premises_factor
                  + ContentsSizeDiscount(sum_insured)
                  + TheftAreaRate(theft_risk)
                  + total_claims_loading
    }

    Rate(exposure: Exposure, bc: BCConfig, risks: RiskScores, total_claims_loading: number): CoverOutcome {
        rated {
            key: "BC",
            name: "Buildings, contents and stock",
            base_premium: buildings_prem + contents_prem + stock_prem,
            limit: bc.buildings_limit + bc.contents_limit + bc.stock_limit,
            excess: 400,
        }
        where flood_banding = Industry.FloodBanding(exposure.industry),
              fire_class = Industry.BuildingsFireClass(exposure.industry),
              contents_fire = Industry.ContentsFireClass(exposure.industry),
              theft_class = Industry.ContentsTheftClass(exposure.industry),
              stock_theft_class = Industry.StockTheftClass(exposure.industry),
              pf = BuildingsContentsStock.PremisesFactor(
                  listed_type: bc.listed_type,
                  flood_risk: risks.flood_risk,
                  flood_banding,
                  has_outdoor_play: bc.has_outdoor_play,
                  has_functions: bc.has_functions,
                  number_of_beds: bc.number_of_beds,
              ),
              buildings_prem = BuildingsPremium(
                  sum_insured: bc.buildings_limit,
                  classification: fire_class,
                  premises_factor: pf,
                  total_claims_loading,
              ),
              contents_prem = ContentsPremium(
                  sum_insured: bc.contents_limit,
                  fire_class: contents_fire,
                  theft_class, theft_risk: risks.theft_risk,
                  premises_factor: pf,
                  total_claims_loading,
              ),
              stock_prem = StockPremium(
                  sum_insured: bc.stock_limit,
                  fire_class: contents_fire,
                  stock_theft_class, theft_risk: risks.theft_risk,
                  premises_factor: pf,
                  total_claims_loading,
              )
    }
}

// --- Business Interruption (BI) — optional ---

namespace BusinessInterruption {
    // BI fire rate (from bi_fire_rates.csv)
    BIFireRate(classification: string): number {
        match classification {
            "C" => 0.15,
            "I" => 0.154,
            "Y" => 0.5,
            _ => 0.08,
        }
    }

    // Basis of cover discount (from basis_of_cover_rates.csv)
    BasisOfCoverDiscount(basis_of_cover: string): number {
        match basis_of_cover {
            "Gross Profit" => 0,
            "Gross Revenue" => -0.5,
            "Increased Cost of Working" => 0.5,
            _ => 0,
        }
    }

    // Indemnity period discount and months (from indemnity_period_rates.csv)
    IndemnityDiscount(indemnity_months: number): number {
        match indemnity_months {
            12 => 0,
            18 => -0.1,
            24 => -0.2,
            36 => -0.3,
            _ => 0,
        }
    }

    // Sum insured discount (from sum_insured_discounts.csv)
    SumInsuredDiscount(sum_insured: number): number {
        match sum_insured {
            [1..125000) => -0.025,
            [125000..250000) => -0.05,
            [250000..375000) => -0.0625,
            [375000..500000) => -0.075,
            [500000..625000) => -0.0875,
            [625000..750000) => -0.1,
            [750000..875000) => -0.125,
            [875000..1000000) => -0.15,
            [1000000..1125000) => -0.1675,
            [1125000..1250000) => -0.175,
            [1250000..1375000) => -0.1875,
            [1375000..1500001) => -0.2,
            _ => 0,
        }
    }

    // Loss of licence rate (from loss_of_license_rates.csv)
    LossOfLicenceDiscount(lol_class: string): number {
        match lol_class {
            "Low" => 0.1,
            "Medium" => 0.125,
            "High" => 0.15,
            _ => 0.15,
        }
    }

    Rate(exposure: Exposure, bi: BIConfig, total_claims_loading: number): CoverOutcome {
        rated {
            key: "BI",
            name: "Business interruption",
            base_premium: bi_premium + lol_premium,
            limit: sum_insured,
            excess: 0,
        }
        where basis_si = bi.basis_of_cover_limit * (bi.indemnity_months / 12),
              sum_insured = basis_si + bi.rent_receivable_limit,
              bi_rate = BIFireRate(classification: Industry.BIFireClass(exposure.industry)),
              bi_loads = SumInsuredDiscount(sum_insured)
                  + IndemnityDiscount(indemnity_months: bi.indemnity_months)
                  + BasisOfCoverDiscount(basis_of_cover: bi.basis_of_cover)
                  + total_claims_loading,
              bi_premium = (sum_insured / 100) * (bi_rate * (1 + bi_loads)),
              lol_class = Industry.LossOfLicenceClass(exposure.industry),
              lol_rate = 0.0125,
              lol_loads = LossOfLicenceDiscount(lol_class) + total_claims_loading,
              lol_premium = if bi.loss_of_licence_limit > 0
                  then (bi.loss_of_licence_limit / 100) * (lol_rate * (1 + lol_loads))
                  else 0
    }
}

// --- Employers Liability (EL) ---

namespace EmployersLiability {
    // Base rate by EL classification (from base_rates.csv)
    BaseRate(classification: string): number {
        match classification {
            "A" => 0.005524,
            "B" => 0.008825,
            "C" => 0.017,
            _ => 0.006825,
        }
    }

    // Turnover size discount (from discounts.csv)
    TurnoverDiscount(turnover: number): number {
        match turnover {
            [0..100000) => 0,
            [100000..150000) => -0.025,
            [150000..200000) => -0.05,
            [200000..250000) => -0.1,
            [250000..500000) => -0.125,
            [500000..750000) => -0.15,
            [750000..) => -0.2,
            _ => 0,
        }
    }

    Rate(exposure: Exposure, total_claims_loading: number): CoverOutcome {
        rated {
            key: "EL",
            name: "Employers liability",
            base_premium: (exposure.turnover / 100) * (base_rate * (1 + total_loads)),
            limit: 10000000,
            excess: 0,
        }
        where classification = Industry.ELClass(exposure.industry),
              base_rate = BaseRate(classification),
              total_loads = TurnoverDiscount(exposure.turnover) + total_claims_loading
    }
}

// --- Portable Business Equipment (PBE) ---

namespace PortableEquipment {
    Rate(limit: number, total_claims_loading: number): CoverOutcome {
        if limit == 0
            then not_available { reason: "PBE not selected" }
            else rated {
                key: "EPE",
                name: "Portable business equipment",
                base_premium: (limit / 100) * (2.5 * (1 + total_claims_loading)),
                limit: limit,
                excess: 400,
            }
    }
}

// --- Terrorism (TER) — optional, excluded from minimum premium floor ---

namespace Terrorism {
    // Postcode zone from terrorism risk score (from postcode_zone.csv + postcode_rates.csv)
    PostcodeRate(terrorism_risk: number): number {
        match terrorism_risk {
            1 => 0.00033,
            2 => 0.00029,
            3 | 4 => 0.00006,
            _ => 0,
        }
    }

    Rate(risks: RiskScores, bc: BCConfig, bi: BIConfig): CoverOutcome {
        if risks.terrorism_risk == 0
            then not_available { reason: "Terrorism risk zone unavailable" }
        else if md_si == 0
            then not_available { reason: "No material damage sum insured" }
            else rated {
                key: "TER",
                name: "Terrorism",
                base_premium: md_si * PostcodeRate(risks.terrorism_risk) * 1.15,
                limit: md_si + bi_si,
                excess: 0,
            }
            where md_si = bc.buildings_limit + bc.contents_limit + bc.stock_limit,
                  bi_si = bi.basis_of_cover_limit * (bi.indemnity_months / 12)
    }
}

// --- Product entry point ---
// Validates exposure, rates all covers, applies claims loading, enforces minimum premium

Product(
    exposure: Exposure,
    claims: ClaimsHistory,
    risks: RiskScores,
    bc: BCConfig,
    bi: BIConfig,
    pl_limit: number,
    pbe_limit: number,
): ProductOutcome {
    // Exposure validation
    if exposure.number_of_employees > 49
        then declined { reasons: ["Maximum 49 employees allowed"] }
    else if exposure.turnover > 5000000
        then declined { reasons: ["Maximum turnover 5,000,000"] }
    else if claims.number_of_claims > 5
        then declined { reasons: ["Too many claims in history"] }
    else if bc.number_of_beds == "over20"
        then declined { reasons: ["Maximum 20 beds allowed"] }
    // Rate all covers, check for failures, assemble product
    else if any not_available in covers
        then referred {
            reasons: collect not_available { reason } in covers => reason,
        }
        else offered {
            covers,
            subtotal,
            minimum_premium: min_prem,
            total_gross_premium: round(total, 2),
            total_net_premium: round(total * (1 - 0.35), 2),
            commission_rate: 0.35,
            currency: "GBP",
        }
        where total_claims_loading = Claims.TotalLoading(exposure, claims),
              pl_cover = PublicLiability.Rate(exposure, limit: pl_limit, total_claims_loading),
              bc_cover = BuildingsContentsStock.Rate(exposure, bc, risks, total_claims_loading),
              bi_cover = BusinessInterruption.Rate(exposure, bi, total_claims_loading),
              el_cover = EmployersLiability.Rate(exposure, total_claims_loading),
              pbe_cover = PortableEquipment.Rate(limit: pbe_limit, total_claims_loading),
              ter_cover = Terrorism.Rate(risks, bc, bi),
              covers = [
                  pl_cover,
                  bc_cover,
                  bi_cover,
                  el_cover,
                  pbe_cover,
                  ter_cover,
              ],
              base_sum = sum(collect rated { base_premium } in covers => base_premium),
              ter_premium = match ter_cover {
                  rated { base_premium } => base_premium,
                  _ => 0,
              },
              subtotal = base_sum - ter_premium,
              min_prem = MinimumPremium(exposure, claims, bc),
              floored_subtotal = if min_prem > subtotal then min_prem else subtotal,
              total = floored_subtotal + ter_premium
}

// --- Multi-industry demonstration ---
// Shows how a product can query multiple rows across selected industries.

MultiIndustryDemo(industries: list(string)): MultiIndustrySummary {
    {
        pl_classes: collect row in industry_config {
            row.code in industries => row.pl_class,
        },
        el_classes: collect row in industry_config {
            row.code in industries => row.el_class,
        },
        deep_frying_rates: collect row in industry_config {
            row.code in industries => row.deep_frying_rate,
        },
        minimum_premiums: collect row in industry_config {
            row.code in industries => row.min_premium_default,
        },
    }
}
`;

// Scenario: Café (DRI-945), 5 employees, £300k turnover, sole trader, 3 years exp, no claims
// Own premises: buildings £500k, contents £100k, stock £50k, not listed
// BI: Gross Profit £200k, 18 months indemnity, no loss of licence
// PBE: £5,000 | Terrorism risk zone 3
export const HOSPITALITY_INPUT = {
  exposure: {
    industry: "DRI-945",
    number_of_employees: 5,
    turnover: 300000,
    is_sole_trader: true,
    years_experience: 3,
  },
  claims: {
    number_of_claims: 0,
    total_claims_value: 0,
  },
  risks: {
    flood_risk: 4,
    theft_risk: 6,
    terrorism_risk: 3,
  },
  bc: {
    buildings_limit: 500000,
    contents_limit: 100000,
    stock_limit: 50000,
    listed_type: "notListed",
    has_outdoor_play: false,
    has_functions: true,
    number_of_beds: "none",
    has_heavy_deep_frying: false,
  },
  bi: {
    basis_of_cover: "Gross Profit",
    basis_of_cover_limit: 200000,
    indemnity_months: 18,
    rent_receivable_limit: 0,
    loss_of_licence_limit: 0,
  },
  pl_limit: 2000000,
  pbe_limit: 5000,
  // Table data loaded from CSV artifact (industry_config.csv)
  _tables: {
    industry_config: parseCSV(industryConfigCSV),
  },
};
