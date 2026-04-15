export const MONEY_EXAMPLE = `// Money Type Plugin Demo
// Demonstrates: money literals, type-safe arithmetic, currency enforcement

// --- Premium calculation with money types ---

type MoneyBreakdown = {
    base_premium: money(GBP),
    discount: money(GBP),
    admin_fee: money(GBP),
    ipt: money(GBP),
    total: money(GBP),
    affordable: bool,
}

BasePremium(risk_score: number): money(GBP) {
    match risk_score {
        [1..3] => £500,
        [4..6] => £750,
        [7..10] => £1250,
        _ => £350,
    }
}

AdminFee(): money(GBP) {
    £35
}

// Insurance premium tax (12% of premium)
IPT(premium: money(GBP)): money(GBP) {
    premium * 0.12
}

// Multi-property discount
PropertyDiscount(num_properties: number, subtotal: money(GBP)): money(GBP) {
    subtotal * discount_rate
    where discount_rate = match num_properties {
        1 => 0,
        2 => 0.05,
        3 => 0.10,
        [4..99] => 0.15,
        _ => 0,
    }
}

// Minimum premium floor
MinPremium(): money(GBP) {
    £250
}

// Full product calculation with money throughout
Product(risk_score: number, num_properties: number): money(GBP) {
    round(total, 2)
    where base = BasePremium(risk_score) * num_properties,
          discount = PropertyDiscount(num_properties, base),
          net = base - discount,
          floor = if net > MinPremium() then net else MinPremium(),
          ipt = IPT(floor),
          total = floor + ipt + AdminFee()
}

// ISO code form works too
EuroExample(): money(EUR) {
    EUR1000 * 1.15
}

// Comparison operators
IsAffordable(premium: money(GBP)): bool {
    premium <= £2000
}

// Full breakdown
Breakdown(risk_score: number, num_properties: number): MoneyBreakdown {
    {
        base_premium: BasePremium(risk_score) * num_properties,
        discount: PropertyDiscount(num_properties, BasePremium(risk_score) * num_properties),
        admin_fee: AdminFee(),
        ipt: IPT(Product(risk_score, num_properties) - AdminFee()),
        total: Product(risk_score, num_properties),
        affordable: IsAffordable(Product(risk_score, num_properties)),
    }
}
`;

export const MONEY_INPUT = {
  risk_score: 5,
  num_properties: 3,
};
