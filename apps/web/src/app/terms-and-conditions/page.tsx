import type { Metadata } from "next";
import { LegalPage, type LegalSection } from "@/components/legal/LegalPage";

export const metadata: Metadata = {
  title: "Terms & Conditions | Betplus",
  description: "Betplus account eligibility, game rules, wallet funding, withdrawals and responsible-play terms.",
};

const sections: LegalSection[] = [
  { id: "agreement", title: "About these terms", content: <>
    <p>These terms describe the rules for using Betplus, including BlackRed, Heritage and Caged, your account and your wallet. Read them alongside the <a href="/privacy-policy">Privacy Policy</a> and the rules shown for each game.</p>
    <p>The service is operated by <strong>Betplus Integrated Services Limited</strong>, 27th Amore Street, Toyin, Allen, Lagos, Nigeria. Contact us at <a href="mailto:support@betplus.com.ng">support@betplus.com.ng</a>. By creating an account or using the service, you agree to these terms. If you do not agree, do not register or place a stake.</p>
  </> },
  { id: "eligibility", title: "Who can use Betplus", content: <>
    <p>You must be at least 18, act on your own behalf and be legally permitted to participate where you are located. Availability in Nigeria does not mean every game is available in every jurisdiction. Location, age, verification and account restrictions may prevent access.</p>
    <p>Provide accurate information, use an account and linked payment wallet belonging to you, and complete the verification required for your account tier. Do not create accounts to bypass limits, exclusions or restrictions, or allow someone else to use your account.</p>
  </> },
  { id: "security", title: "Account access and security", content: <>
    <p>Your phone number is used for account access and verification. Protect your phone, PINs and one-time codes. Never share a code with someone claiming to be support. Tell Betplus promptly if you suspect unauthorised access or lose control of your registered number.</p>
    <p>Additional checks or temporary restrictions may be necessary to investigate suspicious activity or protect funds. Any restriction and its effect on access or withdrawals must be handled consistently with applicable law.</p>
  </> },
  { id: "wallet", title: "Deposits and wallet balances", content: <>
    <p>Betplus uses Nigerian naira and integrates with OPay for payments. Review the amount, destination, any displayed charges and payment conditions before confirming. A payment is complete only when its result is confirmed and reflected in your transaction record.</p>
    <p><strong>Play Balance</strong> holds deposited funds for staking. Under the platform’s current wallet rules, deposited funds are subject to a one-times turnover requirement before withdrawal eligibility. <strong>Winnings Balance</strong> holds settled winnings available for withdrawal, subject to verification, applicable deductions and lawful restrictions.</p>
    <p>Check the wallet’s eligibility and turnover information before depositing. Do not deposit money you need for essential expenses or borrow to play. Payment-provider limits and processing delays may apply.</p>
  </> },
  { id: "games", title: "Tickets, game rules and outcomes", content: <>
    <p>Before confirming a ticket, check your stake, selections, game rules and the displayed payout or multiplier. Rules differ between BlackRed, Heritage and Caged. An accepted ticket and its reference are the record of your entry; a screen animation alone is not proof that a ticket was accepted or won.</p>
    <p>Accepted stakes are committed to the ticket. There is no general right to cancel an accepted ticket unless the game rules or applicable law provide otherwise. Where a game offers cash-out, a request must be received and accepted within the game’s rules; a click or network request does not by itself confirm acceptance.</p>
    <p>Participation involves a risk of losing your stake. Outcomes and previous wins do not guarantee future winnings. Promotions, where offered, must state their separate eligibility, expiry and reward conditions before entry.</p>
  </> },
  { id: "withdrawals", title: "Withdrawals and deductions", content: <>
    <p>Withdrawals are made from eligible balances to your verified linked OPay wallet. Review the available amount and status in your wallet. Identity checks, payment-provider availability, limits and fraud investigations can affect processing time.</p>
    <p>Taxes or statutory deductions may apply where required. Any applicable deduction or charge should be shown in the relevant transaction details. Keep your transaction reference when raising a payment question. A pending payment must be resolved against its confirmed provider status before it is retried or reversed.</p>
  </> },
  { id: "responsible-play", title: "Responsible play and account closure", content: <>
    <p>Play for entertainment within limits you can afford. Use the <a href="/account">Account page</a> to manage the available deposit, stake and session limits, exclusion and account-access controls. Read each confirmation carefully; a restriction may take effect immediately and may not be reversible during its stated period.</p>
    <p>Do not use another account or channel to bypass an exclusion. Account closure does not remove recordkeeping obligations or extinguish a lawful claim to eligible funds. Any remaining balance must be reviewed under the wallet rules and applicable law.</p>
  </> },
  { id: "misuse", title: "Prohibited use", content: <>
    <ul>
      <li>Fraud, money laundering, identity misuse or using payment methods without authorisation.</li>
      <li>Manipulating games, exploiting errors, interfering with systems or using automation to gain an unfair advantage.</li>
      <li>Evading age, location, verification, account or responsible-play restrictions.</li>
      <li>Harassing others or posting unlawful content through any community feature.</li>
    </ul>
    <p>Suspected misuse may lead to investigation, account restrictions or referral to competent authorities. Any withholding, correction or recovery of funds must have a lawful basis; these terms do not authorise arbitrary confiscation of legitimate balances.</p>
  </> },
  { id: "errors", title: "Interruptions and disputes about records", content: <>
    <p>Network failures, maintenance and provider interruptions can affect access and payment timing. If a result is unclear, check your ticket or transaction history before trying again. Record the reference, time and amount, and avoid sending sensitive identity information in an initial report.</p>
    <p>Errors must be investigated using the accepted ticket, wallet ledger and relevant provider records. A correction, refund or resettlement should be explained and recorded. A technical issue does not, by itself, remove your rights under applicable law.</p>
  </> },
  { id: "rights", title: "Content and legal rights", content: <>
    <p>Betplus branding, game artwork and software are protected by applicable intellectual-property rights. You may use them as part of ordinary access to the service; redistribution or commercial reuse requires the relevant permission.</p>
    <p>Nothing in these terms excludes rights or liabilities that cannot lawfully be excluded. Responsibility for a disputed loss must be assessed under applicable law and the circumstances, including the parties’ conduct and the confirmed records.</p>
  </> },
  { id: "complaints", title: "Questions, complaints and applicable law", content: <>
    <p>Email <a href="mailto:support@betplus.com.ng?subject=Betplus%20complaint">support@betplus.com.ng</a> with the subject “Betplus complaint”, or write to Betplus Integrated Services Limited, 27th Amore Street, Toyin, Allen, Lagos, Nigeria. You can contact us without signing in, including after account closure. If an issue remains unresolved, ask for a further review and information about the competent authority for your jurisdiction.</p>
    <p>When raising an issue, include the relevant ticket or payment reference, a description and the outcome you are seeking. Never include an OTP, password or payment PIN.</p>
    <p>Applicable Nigerian law governs the service, including the rules applicable to the jurisdiction where it is offered. Nothing here prevents you from approaching a competent regulator or court or exercising mandatory consumer or privacy rights.</p>
  </> },
  { id: "changes", title: "Updates to these terms", content: <>
    <p>Material changes will be communicated before taking effect. Accepted tickets remain subject to the rules applicable when they were accepted, except where law requires otherwise. The date and version above identify the current terms. If a change requires your agreement, we will request it before you continue with the affected service.</p>
  </> },
];

export default function TermsAndConditionsPage() {
  return <LegalPage kind="terms" title="Terms & Conditions" intro="The rules for your account, your wallet and your play. Take a moment to understand them before you get started." sections={sections} />;
}
