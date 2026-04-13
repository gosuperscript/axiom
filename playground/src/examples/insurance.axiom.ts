export const INSURANCE_EXAMPLE = `// Axiom v1 — Insurance Rating Example
// Try editing this code and see the output update live!

type Endorsement =
    required { code: string, title: string }
  | waived { code: string, reason: string }

type RuleResult =
    ok { factor: number, notes: list(string), endorsements: list(Endorsement) }
  | referred { message: string }

type CoverOutcome =
    not_selected
  | rated { premium: number, loading: number, notes: list(string), endorsements: list(Endorsement) }
  | referred { reasons: list(string) }

type ProductOutcome =
    offered { total: number, covers: list(CoverOutcome) }
  | referred { reasons: list(string) }

BuildingsConstructionRule(quote: { construction: string }): RuleResult {
    match quote.construction {
        "brick"  => ok { factor: 1.00, notes: [], endorsements: [] },
        "stone"  => ok {
            factor: 1.05,
            notes: ["stone_loading"],
            endorsements: [
                required { code: "END-ST01", title: "Structural survey within 5 years" },
            ],
        },
        "timber" => referred { message: "timber_construction" },
        _        => referred { message: "unknown_construction" }
    }
}

BuildingsClaimsRule(quote: { claims_count: number }): RuleResult {
    match {
        quote.claims_count == 0 => ok { factor: 0.95, notes: ["claims_free"], endorsements: [] },
        quote.claims_count <= 2 => ok { factor: 1.00, notes: [], endorsements: [] },
        quote.claims_count == 3 => ok {
            factor: 1.20,
            notes: ["claims_3"],
            endorsements: [
                required { code: "END-CL01", title: "Claims history disclosure" },
            ],
        },
        _                       => referred { message: "claims_too_high" }
    }
}

AggregateRules(
    rules: list(RuleResult),
    base_premium: number,
): CoverOutcome {
    if any referred in rules
        then referred {
            reasons: collect referred { message: m } in rules => m,
        }
        else rated {
            premium: base_premium * product collect in rules {
                ok { factor } => factor,
                _ => 1.00,
            },
            loading: product collect in rules {
                ok { factor } => factor,
                _ => 1.00,
            },
            notes: flatten(collect ok { notes: n } in rules => n),
            endorsements: flatten(collect ok { endorsements: e } in rules => e),
        }
}

BuildingsCover(
    quote: {
        has_buildings: bool,
        construction: string,
        claims_count: number,
        buildings_sum_insured: number,
    },
    base_rate: number,
): CoverOutcome {
    if not quote.has_buildings
        then not_selected
        else AggregateRules(
            rules: [
                BuildingsConstructionRule(quote: quote),
                BuildingsClaimsRule(quote: quote),
            ],
            base_premium: quote.buildings_sum_insured / 1000 * base_rate,
        )
}

ContentsCover(
    quote: {
        has_contents: bool,
        contents_sum_insured: number,
    },
    base_rate: number,
): CoverOutcome {
    if not quote.has_contents
        then not_selected
        else rated {
            premium: quote.contents_sum_insured / 1000 * base_rate,
            loading: 1.00,
            notes: [],
            endorsements: [],
        }
}

AggregateCovers(covers: list(CoverOutcome)): ProductOutcome {
    if any referred in covers
        then referred {
            reasons: flatten(collect referred { reasons: rs } in covers => rs),
        }
        else offered {
            total: sum(collect rated { premium: p } in covers => p),
            covers: covers,
        }
}

Product(
    quote: {
        has_buildings: bool,
        construction: string,
        claims_count: number,
        buildings_sum_insured: number,
        has_contents: bool,
        contents_sum_insured: number,
    },
    rates: {
        buildings_rate: number,
        contents_rate: number,
    },
): ProductOutcome {
    AggregateCovers(covers: [
        BuildingsCover(quote: quote, base_rate: rates.buildings_rate),
        ContentsCover(quote: quote, base_rate: rates.contents_rate),
    ])
}
`;

export const INSURANCE_INPUT = {
  quote: {
    has_buildings: true,
    construction: "brick",
    claims_count: 1,
    buildings_sum_insured: 500000,
    has_contents: true,
    contents_sum_insured: 50000,
  },
  rates: {
    buildings_rate: 0.50,
    contents_rate: 0.75,
  },
};
