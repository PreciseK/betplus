const STEPS = [
  ["Create one account", "Confirm your OPay-linked phone number online, or dial *7006# for instant gateway authentication without OTP typing."],
  ["Seamless funding", "Top up your Play Balance online, or on USSD let direct withdrawal automatically fund your stake straight from your OPay balance."],
  ["Choose, review, confirm", "Select your BlackRed cards, Heritage regalia, or Caged bird escape count (1-5 birds). Always see your odds and return before committing."],
  ["Keep the receipt", "Every result records the gross prize, tax withheld, net credit, and reference—shown on screen and sent immediately via SMS."],
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
