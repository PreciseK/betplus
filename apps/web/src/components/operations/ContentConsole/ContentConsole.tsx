"use client";

import { useEffect, useState } from "react";
import { backOfficeGateway, type BackOfficeHeritageCatalogueItem } from "@betplus/api-client";
import { Button } from "@/components/ui/Button/Button";
import { Icon } from "@/components/ui/Icon/Icon";
import styles from "@/components/operations/OperationsConsole.module.css";

export function ContentConsole() {
  const [items, setItems] = useState<BackOfficeHeritageCatalogueItem[]>();
  const [loadFailed, setLoadFailed] = useState(false);
  const [receipt, setReceipt] = useState("");
  const [gateErrors, setGateErrors] = useState<Record<number, string[]>>({});
  const [publishingNumber, setPublishingNumber] = useState<number>();

  function load() {
    if (!backOfficeGateway.hasSession()) {
      setLoadFailed(true);
      return;
    }
    backOfficeGateway.heritageCatalogue()
      .then((result) => setItems(result.items))
      .catch(() => setLoadFailed(true));
  }

  useEffect(load, []);

  async function publish(itemNumber: number) {
    setPublishingNumber(itemNumber);
    setReceipt("");
    try {
      const result = await backOfficeGateway.publishHeritageCatalogueItem(itemNumber);
      setGateErrors((current) => ({ ...current, [itemNumber]: result.gate_errors }));
      if (result.gate_errors.length === 0) {
        setReceipt(`Item ${itemNumber} published.`);
        load();
      }
    } catch {
      setGateErrors((current) => ({ ...current, [itemNumber]: ["Could not publish that item. Please try again."] }));
    } finally {
      setPublishingNumber(undefined);
    }
  }

  if (loadFailed) {
    return <div className={styles.page}><p className={styles.muted}>Live catalogue data could not be loaded. Sign in again if this persists.</p></div>;
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Content</p>
          <h1>Heritage catalogue</h1>
          <p>All 90 real regalia items. Publishing requires a recorded cultural-advisor sign-off reference.</p>
        </div>
      </header>

      {receipt && <div className={styles.receipt} role="status"><Icon name="check" />{receipt}</div>}

      <section className={styles.section} aria-labelledby="catalogue-title">
        <header className={styles.sectionHeader}>
          <div><h2 id="catalogue-title">Catalogue items</h2><p>{items?.length ?? 0} items.</p></div>
        </header>

        {items === undefined ? <p className={styles.muted}>Loading…</p> : (
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <caption className="sr-only">Heritage catalogue items and their publication status</caption>
              <thead><tr><th scope="col">#</th><th scope="col">Item</th><th scope="col">Origin</th><th scope="col">Sign-off</th><th scope="col">Status</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
              <tbody>{items.map((item) => (
                <>
                  <tr key={item.number}>
                    <th scope="row" data-label="#">{item.number}</th>
                    <td data-label="Item"><strong>{item.canonical_name}</strong><span>{item.local_name}</span></td>
                    <td data-label="Origin">{item.origin}</td>
                    <td data-label="Sign-off">{item.advisor_sign_off_reference ?? "None recorded"}</td>
                    <td data-label="Status"><span className={styles.statusLabel}>{item.publication_status === "approved" ? "Published" : "Preview only"}</span></td>
                    <td data-label="Actions">
                      {item.publication_status !== "approved" && (
                        <Button variant="secondary" onClick={() => publish(item.number)} status={publishingNumber === item.number ? "loading" : "idle"}>
                          Publish
                        </Button>
                      )}
                    </td>
                  </tr>
                  {gateErrors[item.number] && gateErrors[item.number].length > 0 && (
                    <tr key={`${item.number}-errors`}>
                      <td colSpan={6}>
                        <p className={styles.fieldError} role="alert"><Icon name="error" />{gateErrors[item.number].join(" ")}</p>
                      </td>
                    </tr>
                  )}
                </>
              ))}</tbody>
            </table>
          </div>
        )}
      </section>

      <aside className={styles.contractNote} aria-labelledby="content-boundary-title">
        <h2 id="content-boundary-title">Integration boundary</h2>
        <p>There is no draft/cultural-review workflow state machine — an item is either published or not. Every seeded item currently has no sign-off reference recorded, so publish is refused for all of them until Compliance records one.</p>
      </aside>
    </div>
  );
}
