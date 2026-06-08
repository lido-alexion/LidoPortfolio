/**
 * Stock-level XIRR from transactions + terminal value (qty × latest close).
 * Mirrors backend XirrService logic for holdings display.
 */

function parseDate(value) {
    const d = new Date(value);
    d.setHours(0, 0, 0, 0);
    return d;
}

function solveXirr(flows, guess = 0.1) {
    let rate = guess;
    const base = flows[0].date.getTime();

    for (let i = 0; i < 100; i += 1) {
        let npv = 0;
        let derivative = 0;

        for (const flow of flows) {
            const years = (flow.date.getTime() - base) / (365 * 24 * 60 * 60 * 1000);
            const denominator = (1 + rate) ** years;
            if (denominator === 0) return null;
            npv += flow.amount / denominator;
            derivative -= (years * flow.amount) / (denominator * (1 + rate));
        }

        if (Math.abs(npv) < 1e-7) {
            return Math.round(rate * 10000) / 100;
        }

        if (Math.abs(derivative) < 1e-10) break;

        rate -= npv / derivative;
        if (rate <= -0.9999) rate = -0.9999;
    }

    return null;
}

/**
 * @param {Array} transactions - user transactions for one stock
 * @param {number} quantity - current holding qty
 * @param {number|null} latestClose - latest market close
 * @param {Date} [asOf]
 * @returns {number|null} XIRR percent
 */
export function calculateStockXirr(transactions, quantity, latestClose, asOf = new Date()) {
    const flows = transactions.map((tx) => {
        let amount = Number(tx.quantity) * Number(tx.price) + Number(tx.fees || 0);
        if (tx.type === 'buy') amount = -amount;
        return { date: parseDate(tx.transaction_date), amount };
    });

    const qty = Number(quantity);
    const close = Number(latestClose);
    if (qty > 0 && close > 0) {
        flows.push({ date: parseDate(asOf), amount: qty * close });
    }

    if (flows.length < 2) return null;

    const hasNegative = flows.some((f) => f.amount < 0);
    const hasPositive = flows.some((f) => f.amount > 0);
    if (!hasNegative || !hasPositive) return null;

    return solveXirr(flows);
}
