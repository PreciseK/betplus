const STEPS = [
  ["Create your account", "Register with your OPay-linked phone number and complete the required verification."],
  ["Choose a game", "Read the rules, choose your stake and review the details before you confirm."],
  ["Check your activity", "Use your transaction history and ticket references to review deposits, withdrawals and results."],
] as const;

export function HowItWorks() {
  return (
    <section className="how-section" id="how-it-works" aria-labelledby="how-title">
      <div className="section-heading"><p className="eyebrow">Getting started</p><h2 id="how-title">Before your first round.</h2></div>
      <ol className="steps-list">
        {STEPS.map(([title, body], index) => <li key={title}><span className="step-number" aria-hidden="true">0{index + 1}</span><h3>{title}</h3><p>{body}</p></li>)}
      </ol>
    </section>
  );
}
