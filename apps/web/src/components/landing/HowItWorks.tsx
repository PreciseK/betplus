const STEPS = [
  ["Create one account", "Confirm your OPay-linked phone number and complete the checks required for adults to play."],
  ["Fund your Play Balance", "Add money through OPay and see the status in Activity—even if the network takes longer than expected."],
  ["Choose, review, confirm", "See your selection, stake, odds, and possible return before any ticket is created."],
  ["Keep the receipt", "Every result records the gross prize, tax withheld, net credit, time, and ticket reference."],
] as const;

export function HowItWorks() {
  return (
    <section className="how-section" id="how-it-works" aria-labelledby="how-title">
      <div className="section-heading section-heading-light reveal-on-scroll">
        <p className="eyebrow">Clear from stake to receipt</p>
        <h2 id="how-title">Know what happens<br />at every step.</h2>
      </div>
      <ol className="steps-list">
        {STEPS.map(([title, body], index) => (
          <li className="reveal-on-scroll" key={title}>
            <span className="step-number">{String(index + 1).padStart(2, "0")}</span>
            <div><h3>{title}</h3><p>{body}</p></div>
          </li>
        ))}
      </ol>
    </section>
  );
}
