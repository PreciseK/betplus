import type { Metadata } from "next";
import { HomepageConcept } from "./HomepageConcept";

export const metadata: Metadata = {
  title: "Betplus — Homepage concept",
  robots: { index: false, follow: false },
};

export default function HomepageConceptPage() {
  return <HomepageConcept />;
}
