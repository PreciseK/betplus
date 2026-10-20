"use client";

import { useEffect, useId, useRef, type KeyboardEvent } from "react";
import { createPortal } from "react-dom";
import type { HeritageBoardPosition } from "@/mocks/heritage";
import { RegaliaArtwork } from "./RegaliaArtwork";
import styles from "./RegaliaReview.module.css";

export function RegaliaReview({
  position,
  returnFocus,
  onClose,
}: {
  position: HeritageBoardPosition;
  returnFocus: HTMLButtonElement | null;
  onClose: () => void;
}) {
  const titleId = useId();
  const closeRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    closeRef.current?.focus();
    return () => {
      document.body.style.overflow = previousOverflow;
      returnFocus?.focus({ preventScroll: true });
    };
  }, [returnFocus]);
  function handleKey(event: KeyboardEvent<HTMLDivElement>) {
    if (event.key === "Escape") {
      event.preventDefault();
      event.stopPropagation();
      onClose();
      return;
    }
    if (event.key !== "Tab") return;
    const buttons = Array.from(
      panelRef.current?.querySelectorAll<HTMLButtonElement>("button") ?? [],
    );
    const first = buttons[0],
      last = buttons[buttons.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  }
  const item = position.item;
  return createPortal(
    <div
      className={styles.backdrop}
      onClick={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <div
        className={styles.panel}
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        onKeyDown={handleKey}
        data-regalia-review
      >
        <header>
          <span>
            Regalia collection · No. {item.number.toString().padStart(2, "0")}
          </span>
          <button
            ref={closeRef}
            type="button"
            onClick={onClose}
            aria-label="Close regalia review"
          >
            ×
          </button>
        </header>
        <div className={styles.content}>
          <figure className={styles.display}>
            <RegaliaArtwork item={item} />
            <figcaption>
              {item.imageUrl
                ? "Catalogue artwork"
                : "Concept artwork · regalia review pending"}
            </figcaption>
          </figure>
          <div className={styles.description}>
            <p className={styles.origin}>{item.origin}</p>
            <h2 id={titleId}>{item.localName ?? item.canonicalName}</h2>
            {item.localName && (
              <p className={styles.canonical}>{item.canonicalName}</p>
            )}
            <p className={styles.context}>{item.context}</p>
            <dl>
              <div>
                <dt>Equipment slot</dt>
                <dd>
                  {
                    {
                      head: "Head",
                      neck: "Neck",
                      torso: "Body",
                      waist: "Waist",
                      wrist: "Wrist",
                      hand: "Hand",
                      feet: "Feet",
                    }[item.slot]
                  }
                </dd>
              </div>
              <div>
                <dt>Board position</dt>
                <dd>
                  {position.position} ·{" "}
                  {position.selected ? "Your pick" : "Not picked"}
                </dd>
              </div>
              <div>
                <dt>Round status</dt>
                <dd>
                  {position.selected && position.winning
                    ? "Matched · added to your royal"
                    : position.selected
                      ? "Picked · not matched"
                      : position.winning
                        ? "Not picked · winning position"
                        : "Not picked · not matched"}
                </dd>
              </div>
            </dl>
            <button
              className={styles.returnButton}
              type="button"
              onClick={onClose}
            >
              Back to the arena
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body,
  );
}
