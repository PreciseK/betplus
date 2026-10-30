import s from "./doors.module.css";

export default function BigMan() {
  return <div className={s.bigMan} role="img" aria-label="Big Man giving you a thumbs up">
    {/* eslint-disable-next-line @next/next/no-img-element */}
    <img src="/assets/doors/big-man-thumbs-up.png" alt="" />
  </div>;
}
