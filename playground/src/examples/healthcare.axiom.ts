export const HEALTHCARE_EXAMPLE = `// Beazley Healthcare Professional V7
// Translated from Abacus ProductSchemeBuilder + CoverSchemeBuilder

type Endorsement =
    applied { code: string, title: string }

type CoverOutcome =
    rated {
        gross_premium: money(GBP),
        net_premium: money(GBP),
        commission: money(GBP),
        excess: money(GBP),
        limit: number,
        inquest_costs: money(GBP),
        endorsements: list(Endorsement),
    }
  | referred { reason: string }

type ProductOutcome =
    offered {
        cover: CoverOutcome,
        umr: string,
        currency: string,
        jurisdiction: string,
    }
  | declined { reasons: list(string) }

// Inline premium lookup table (from premium.csv)
PremiumLookup(industry: string, limit: number): money(GBP) {
    match industry {
        "DRI-106" => match limit { 500000 => £1125, 1000000 => £1500, 2000000 => £1950, 3000000 => £2175, 4000000 => £2400, 5000000 => £2475, _ => £0 },
        "DRI-129" => match limit { 500000 => £750, 1000000 => £1000, 2000000 => £1300, 3000000 => £1450, 4000000 => £1600, 5000000 => £1650, _ => £0 },
        "DRI-138" => match limit { 500000 => £225, 1000000 => £300, 2000000 => £390, 3000000 => £435, 4000000 => £480, 5000000 => £495, _ => £0 },
        "DRI-139" => match limit { 500000 => £263, 1000000 => £350, 2000000 => £455, 3000000 => £508, 4000000 => £560, 5000000 => £578, _ => £0 },
        "DRI-140" => match limit { 500000 => £225, 1000000 => £300, 2000000 => £390, 3000000 => £435, 4000000 => £480, 5000000 => £495, _ => £0 },
        "DRI-170" => match limit { 500000 => £75, 1000000 => £100, 2000000 => £130, 3000000 => £145, 4000000 => £160, 5000000 => £165, _ => £0 },
        "DRI-198" => match limit { 500000 => £938, 1000000 => £1250, 2000000 => £1625, 3000000 => £1813, 4000000 => £2000, 5000000 => £2063, _ => £0 },
        "DRI-236" => match limit { 500000 => £45, 1000000 => £60, 2000000 => £78, 3000000 => £87, 4000000 => £96, 5000000 => £99, _ => £0 },
        "DRI-247" => match limit { 500000 => £563, 1000000 => £750, 2000000 => £975, 3000000 => £1088, 4000000 => £1200, 5000000 => £1238, _ => £0 },
        "DRI-253" => match limit { 500000 => £900, 1000000 => £1200, 2000000 => £1560, 3000000 => £1740, 4000000 => £1920, 5000000 => £1980, _ => £0 },
        "DRI-263" => match limit { 500000 => £750, 1000000 => £1000, 2000000 => £1300, 3000000 => £1450, 4000000 => £1600, 5000000 => £1650, _ => £0 },
        "DRI-295" => match limit { 500000 => £900, 1000000 => £1200, 2000000 => £1560, 3000000 => £1740, 4000000 => £1920, 5000000 => £1980, _ => £0 },
        "DRI-318" => match limit { 500000 => £563, 1000000 => £750, 2000000 => £975, 3000000 => £1088, 4000000 => £1200, 5000000 => £1238, _ => £0 },
        "DRI-319" => match limit { 500000 => £49, 1000000 => £65, 2000000 => £85, 3000000 => £94, 4000000 => £104, 5000000 => £107, _ => £0 },
        _ => £0,
    }
}

// Inline excess lookup table (from excess.csv)
ExcessLookup(industry: string): money(GBP) {
    match industry {
        "DRI-106" => £2000,
        "DRI-129" => £1000,
        "DRI-138" => £500,
        "DRI-139" => £250,
        "DRI-140" => £500,
        "DRI-170" => £150,
        "DRI-198" => £1000,
        "DRI-236" => £500,
        "DRI-247" => £1000,
        "DRI-253" => £2000,
        "DRI-263" => £1000,
        "DRI-295" => £2000,
        "DRI-318" => £1000,
        "DRI-319" => £250,
        _ => £0,
    }
}

// Industry-specific endorsements
IndustryEndorsements(industry: string): list(Endorsement) {
    match industry {
        "DRI-129" => [
            applied { code: "END-01", title: "Chiropodist / Podiatrist Exclusion" },
        ],
        "DRI-138" | "DRI-139" | "DRI-140" => [
            applied { code: "END-03", title: "Direct Access Extension" },
            applied { code: "END-12", title: "Teeth Whitening Condition" },
        ],
        "DRI-247" => [
            applied { code: "END-08", title: "Opticians / Optical Exclusion" },
        ],
        "DRI-263" => [
            applied { code: "END-10", title: "Professional Sports Exclusion" },
            applied { code: "END-11", title: "Spinal Joint Manipulation Exclusion" },
        ],
        "DRI-318" => [
            applied { code: "END-02", title: "Diagnostic and Interpretation Exclusion" },
        ],
        _ => [],
    }
}

// Cover calculation
HealthcareProfessional(
    industry: string,
    limit: number,
    commission_rate: number,
): CoverOutcome {
    if PremiumLookup(industry: industry, limit: limit) == £0
        then referred { reason: "Industry not rated" }
        else rated {
            gross_premium: PremiumLookup(industry: industry, limit: limit),
            net_premium: round(PremiumLookup(industry: industry, limit: limit) * (1 - commission_rate), 2),
            commission: round(PremiumLookup(industry: industry, limit: limit) * commission_rate, 2),
            excess: ExcessLookup(industry: industry),
            limit: limit,
            inquest_costs: £25000,
            endorsements: IndustryEndorsements(industry: industry),
        }
}

// Product assembly — wraps cover outcome with product-level metadata
// Propagates cover referrals as product declines
BuildProduct(cover: CoverOutcome): ProductOutcome {
    match cover {
        referred { reason } => declined { reasons: [reason] },
        _ => offered {
            cover: cover,
            umr: "B1792SPR2500004A",
            currency: "GBP",
            jurisdiction: "Great Britain, Northern Ireland, Isle of Man and Channel Islands",
        },
    }
}

// Product entry point — validates exposure constraints, then rates
Product(
    industry: string,
    limit: number,
    number_of_employees: number,
): ProductOutcome {
    if number_of_employees > 1
        then declined { reasons: ["Maximum 1 employee allowed"] }
        else BuildProduct(cover: HealthcareProfessional(
            industry: industry,
            limit: limit,
            commission_rate: 0.33,
        ))
}
`;

// Scenario: Dental Hygienist (DRI-138) at £1,000,000 limit, sole trader
export const HEALTHCARE_INPUT = {
  industry: "DRI-138",
  limit: 1000000,
  number_of_employees: 1,
};
