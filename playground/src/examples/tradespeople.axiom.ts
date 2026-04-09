export const TRADESPEOPLE_EXAMPLE = `// Covea Tradespeople Platinum V2
// Translated from Abacus ProductSchemeBuilder + 6 CoverSchemeBuilders
// Subset: 2 industries (DRI-103, DRI-284), employees 1-3

type CoverOutcome =
    rated {
        key: string,
        name: string,
        base_premium: money(GBP),
        limit: number,
        excess: money(GBP),
    }
  | not_available { reason: string }

type ProductOutcome =
    offered {
        covers: dict(CoverOutcome),
        total_gross_premium: money(GBP),
        total_net_premium: money(GBP),
        currency: string,
    }
  | declined { reasons: list(string) }
  | referred { reasons: dict(string) }

// --- Product-level adjustment factors (from CSV lookup tables) ---

namespace Adjustments {
    // Postcode group relativity (from group_relativity.csv, 50 groups)
    GroupRelativity(postcode_group: number): number {
        match postcode_group {
            1 => 0.85, 5 => 0.885, 10 => 0.924, 15 => 0.962,
            20 => 0.992, 22 => 1, 25 => 1.012, 30 => 1.032,
            35 => 1.055, 40 => 1.08, 45 => 1.108, 50 => 1.15,
            _ => 1,
        }
    }

    // Years of experience relativity (from years_experience_relativities.csv)
    YearsExperienceRelativity(years_experience: number): number {
        match years_experience {
            [0..1] => 0.92,
            2 => 0.98,
            3 => 1.02,
            4 => 1.06,
            5 => 1.1,
            6 => 1.09,
            7 => 1.08,
            8 => 1.07,
            9 => 1.06,
            10 => 1.05,
            11 => 1.04,
            12 => 1.02,
            13 | 14 => 1.01,
            [15..] => 1,
            _ => 1,
        }
    }

    // Claims loading — applies when policyholder has prior claims
    ClaimsLoading(number_of_claims: number, years_since_last_claim: number): number {
        if number_of_claims == 0
            then 1
            else match years_since_last_claim {
                [0..2) => 1.1,
                [2..3) => 1.075,
                [3..4) => 1.05,
                [4..5) => 1.025,
                _ => 1,
            }
    }

    // No-claims discount — applies when policyholder has zero claims
    NoClaimsLoading(number_of_claims: number, years_experience: number): number {
        if number_of_claims > 0
            then 1
            else match years_experience {
                [0..1) => 1,
                [1..2) => 0.95,
                [2..3) => 0.9,
                [3..4) => 0.85,
                [4..5) => 0.8,
                [5..] => 0.75,
                _ => 1,
            }
    }

    // Combined adjustment factor: product of all relativities
    Factor(
        postcode_group: number,
        years_experience: number,
        number_of_claims: number,
        years_since_last_claim: number,
    ): number {
        GroupRelativity(postcode_group)
        * YearsExperienceRelativity(years_experience)
        * ClaimsLoading(number_of_claims, years_since_last_claim)
        * NoClaimsLoading(number_of_claims, years_experience)
    }
}

// --- Public Liability (PL) ---

namespace PublicLiability {
    // 3-dimensional lookup: industry x limit x employees (from premium.csv, ~5700 rows)
    BasePremium(industry: string, limit: number, employees: number): money(GBP) {
        match (industry, limit, employees) {
            ("DRI-103", 1000000, 1) => £163, ("DRI-103", 1000000, 2) => £256, ("DRI-103", 1000000, 3) => £398,
            ("DRI-103", 2000000, 1) => £200, ("DRI-103", 2000000, 2) => £313, ("DRI-103", 2000000, 3) => £485,
            ("DRI-103", 5000000, 1) => £251, ("DRI-103", 5000000, 2) => £391, ("DRI-103", 5000000, 3) => £609,
            ("DRI-284", 1000000, 1) => £275, ("DRI-284", 1000000, 2) => £428, ("DRI-284", 1000000, 3) => £666,
            ("DRI-284", 2000000, 1) => £336, ("DRI-284", 2000000, 2) => £518, ("DRI-284", 2000000, 3) => £812,
            ("DRI-284", 5000000, 1) => £479, ("DRI-284", 5000000, 2) => £910, ("DRI-284", 5000000, 3) => £1323,
            _ => £0,
        }
    }

    Excess(industry: string): money(GBP) {
        match industry {
            "DRI-103" => £100,
            "DRI-284" => £250,
            _ => £100,
        }
    }

    Rate(industry: string, limit: number, employees: number): CoverOutcome {
        if bp == £0
            then not_available { reason: "No PL rate for industry" }
            else rated {
                key: "PL",
                name: "Public Liability",
                base_premium: bp,
                limit: limit,
                excess: Excess(industry),
            }
            where bp = BasePremium(industry, limit, employees)
    }
}

// --- Employers Liability (EL) ---

namespace EmployersLiability {
    // Fixed limit 10M. Premium per industry. Sole traders: insurable workers = max(0, manual - 1)
    BasePremium(industry: string): money(GBP) {
        match industry {
            "DRI-103" => £137,
            "DRI-284" => £1023,
            _ => £0,
        }
    }

    InsurableManualWorkers(manual_workers: number, business_type: string): number {
        if business_type == "sole_trader"
            then max(0, manual_workers - 1)
            else manual_workers
    }

    Rate(industry: string, manual_workers: number, business_type: string): CoverOutcome {
        if bp == £0
            then not_available { reason: "No EL rate for industry" }
            else rated {
                key: "EL",
                name: "Employers Liability",
                base_premium: bp * InsurableManualWorkers(manual_workers, business_type),
                limit: £10000000,
                excess: £0,
            }
            where bp = BasePremium(industry)
    }
}

// --- Portable Tools and Equipment (PTE) ---

namespace PortableTools {
    // Simple limit-based premium, no industry dependency (from premium.csv, 5 rows)
    BasePremium(limit: number): money(GBP) {
        match limit {
            1000 => £59.70,
            2500 => £126.35,
            5000 => £192.92,
            7500 => £244.86,
            10000 => £296.80,
            _ => £0,
        }
    }

    Rate(limit: number): CoverOutcome {
        if bp == £0
            then not_available { reason: "Invalid PTE limit" }
            else rated {
                key: "PTE",
                name: "Portable Tools and Equipment",
                base_premium: bp,
                limit: limit,
                excess: £60,
            }
            where bp = BasePremium(limit)
    }
}

// --- Own Plant and Machinery (OPM) ---

namespace OwnPlant {
    // Only available for eligible industries. Premium by limit x manual workers.
    BasePremium(limit: number, manual_workers: number): money(GBP) {
        match (limit, manual_workers) {
            (5000, 1) => £78.11, (5000, 2) => £103.75, (5000, 3) => £128.20,
            (10000, 1) => £104.15, (10000, 2) => £138.33, (10000, 3) => £170.93,
            (25000, 1) => £115.28, (25000, 2) => £153.43, (25000, 3) => £190.00,
            _ => £0,
        }
    }

    Rate(industry: string, limit: number, manual_workers: number): CoverOutcome {
        if industry not in ["DRI-284"]
            then not_available { reason: "Industry not eligible for OPM" }
        else if bp == £0
            then not_available { reason: "Invalid OPM limit/workers combination" }
            else rated {
                key: "OPM",
                name: "Own Plant and Machinery",
                base_premium: bp,
                limit: limit,
                excess: £250,
            }
            where bp = BasePremium(limit, manual_workers)
    }
}

// --- Hired In Plant and Machinery (HPM) ---

namespace HiredPlant {
    // Only available for eligible industries. Premium by limit x manual workers.
    BasePremium(limit: number, manual_workers: number): money(GBP) {
        match (limit, manual_workers) {
            (10000, 1) => £111.30, (10000, 2) => £145.48, (10000, 3) => £179.67,
            (25000, 1) => £123.23, (25000, 2) => £162.18, (25000, 3) => £199.55,
            (50000, 1) => £154.23, (50000, 2) => £202.73, (50000, 3) => £249.63,
            _ => £0,
        }
    }

    Rate(industry: string, limit: number, manual_workers: number): CoverOutcome {
        if industry not in ["DRI-284"]
            then not_available { reason: "Industry not eligible for HPM" }
        else if bp == £0
            then not_available { reason: "Invalid HPM limit/workers combination" }
            else rated {
                key: "HPM",
                name: "Hired In Plant and Machinery",
                base_premium: bp,
                limit: limit,
                excess: £250,
            }
            where bp = BasePremium(limit, manual_workers)
    }
}

// --- Contract Works (CW) ---

namespace ContractWorks {
    // Industry band determines premium tier (from industry_bands.csv)
    IndustryBand(industry: string): number {
        match industry {
            "DRI-284" => 4,
            _ => 0,
        }
    }

    // Premium by limit x employees x industry band (from premium.csv)
    BasePremium(limit: number, employees: number, band: number): money(GBP) {
        match (limit, band, employees) {
            (100000, 1, 1) => £120.05, (100000, 1, 2) => £155.82, (100000, 1, 3) => £186.03,
            (100000, 2, 1) => £141.51, (100000, 2, 2) => £183.65, (100000, 2, 3) => £218.63,
            (100000, 3, 1) => £148.67, (100000, 3, 2) => £193.18, (100000, 3, 3) => £229.75,
            (100000, 4, 1) => £162.98, (100000, 4, 2) => £211.47, (100000, 4, 3) => £251.22,
            (250000, 1, 1) => £133.56, (250000, 1, 2) => £173.31, (250000, 1, 3) => £206.70,
            (250000, 2, 1) => £157.41, (250000, 2, 2) => £204.32, (250000, 2, 3) => £243.27,
            (250000, 3, 1) => £165.36, (250000, 3, 2) => £214.65, (250000, 3, 3) => £255.20,
            (250000, 4, 1) => £181.26, (250000, 4, 2) => £235.32, (250000, 4, 3) => £279.84,
            _ => £0,
        }
    }

    Rate(industry: string, limit: number, employees: number): CoverOutcome {
        if band == 0
            then not_available { reason: "Industry not eligible for Contract Works" }
        else if bp == £0
            then not_available { reason: "Invalid CW limit/employees combination" }
            else rated {
                key: "CW",
                name: "Contract Works",
                base_premium: bp,
                limit: limit,
                excess: £250,
            }
            where band = IndustryBand(industry),
                  bp = BasePremium(limit, employees, band)
    }
}

// --- Product entry point ---
// Validates exposure constraints, rates all covers, applies shared adjustments

Product(
    industry: string,
    number_of_employees: number,
    manual_workers: number,
    business_type: string,
    turnover: number,
    postcode_group: number,
    years_experience: number,
    number_of_claims: number,
    years_since_last_claim: number,
    pl_limit: number,
    pte_limit: number,
    opm_limit: number,
    hpm_limit: number,
    cw_limit: number,
): ProductOutcome {
    if number_of_employees > 10
        then declined { reasons: ["Maximum 10 employees allowed"] }
    else if turnover > 2000000
        then declined { reasons: ["Maximum turnover 2,000,000"] }
    else if manual_workers > number_of_employees
        then declined { reasons: ["Manual workers cannot exceed total employees"] }
    else if number_of_claims > 1
        then declined { reasons: ["Maximum 1 claim in last 5 years"] }
    else if any not_available {} in covers
        then referred {
            reasons: collect not_available { reason } in covers => reason,
        }
        else offered {
            covers: covers,
            total_gross_premium: round(base_sum * adj, 2),
            total_net_premium: round(base_sum * adj * 0.65, 2),
            currency: "GBP",
        }
        where covers = {
                  pl: PublicLiability.Rate(industry, limit: pl_limit, employees: number_of_employees),
                  el: EmployersLiability.Rate(industry, manual_workers, business_type),
                  pte: PortableTools.Rate(limit: pte_limit),
                  opm: OwnPlant.Rate(industry, limit: opm_limit, manual_workers),
                  hpm: HiredPlant.Rate(industry, limit: hpm_limit, manual_workers),
                  cw: ContractWorks.Rate(industry, limit: cw_limit, employees: number_of_employees),
              },
              adj = Adjustments.Factor(postcode_group, years_experience, number_of_claims, years_since_last_claim),
              base_sum = sum(collect rated { base_premium } in covers => base_premium)
}
`;

// Scenario: Roofer (DRI-284), 2 employees, limited company, no claims, 5 years experience
export const TRADESPEOPLE_INPUT = {
  industry: "DRI-284",
  number_of_employees: 2,
  manual_workers: 2,
  business_type: "limited_company",
  turnover: 500000,
  postcode_group: 22,
  years_experience: 5,
  number_of_claims: 0,
  years_since_last_claim: 0,
  pl_limit: 2000000,
  pte_limit: 5000,
  opm_limit: 10000,
  hpm_limit: 25000,
  cw_limit: 100000,
};
