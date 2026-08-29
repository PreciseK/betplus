"use client";

import { useEffect } from "react";

export function LandingMotion() {
  useEffect(() => {
    const body = document.body;
    const root = document.documentElement;
    const navToggle = document.querySelector<HTMLButtonElement>("[data-nav-toggle]");
    const nav = document.querySelector<HTMLElement>("[data-nav]");
    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
    const finePointer = window.matchMedia("(hover: hover) and (pointer: fine)");
    const timers: ReturnType<typeof setTimeout>[] = [];
    const cleanups: Array<() => void> = [];

    const frame = requestAnimationFrame(() => {
      body.classList.add("is-ready");
      document.querySelector(".t-stagger")?.classList.add("is-shown");
    });

    if (navToggle && nav) {
      const srLabel = navToggle.querySelector<HTMLElement>(".sr-only");
      const closeMenu = (returnFocus = false) => {
        navToggle.setAttribute("aria-expanded", "false");
        if (srLabel) srLabel.textContent = "Open menu";
        nav.classList.remove("is-open");
        nav.classList.add("is-closing");
        body.classList.remove("nav-open");
        timers.push(setTimeout(() => nav.classList.remove("is-closing"), 220));
        if (returnFocus) navToggle.focus();
      };
      const toggleMenu = () => {
        const willOpen = navToggle.getAttribute("aria-expanded") !== "true";
        navToggle.setAttribute("aria-expanded", String(willOpen));
        if (srLabel) srLabel.textContent = willOpen ? "Close menu" : "Open menu";
        nav.classList.remove("is-closing");
        nav.classList.toggle("is-open", willOpen);
        body.classList.toggle("nav-open", willOpen);
      };
      const handleNavClick = (event: Event) => {
        if ((event.target as Element).closest("a")) closeMenu();
      };
      const handleKey = (event: KeyboardEvent) => {
        if (event.key === "Escape" && nav.classList.contains("is-open")) closeMenu(true);
      };
      const handleResize = () => {
        if (window.innerWidth > 1100 && nav.classList.contains("is-open")) closeMenu();
      };
      navToggle.addEventListener("click", toggleMenu);
      nav.addEventListener("click", handleNavClick);
      document.addEventListener("keydown", handleKey);
      window.addEventListener("resize", handleResize);
      cleanups.push(() => navToggle.removeEventListener("click", toggleMenu));
      cleanups.push(() => nav.removeEventListener("click", handleNavClick));
      cleanups.push(() => document.removeEventListener("keydown", handleKey));
      cleanups.push(() => window.removeEventListener("resize", handleResize));
    }

    document.querySelectorAll<HTMLElement>(".t-tilt").forEach((tilt) => {
      const card = tilt.querySelector<HTMLElement>(".t-tilt-card");
      if (!card) return;
      const track = (event: PointerEvent) => {
        if (reduceMotion.matches || !finePointer.matches) return;
        const rect = tilt.getBoundingClientRect();
        const px = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
        const py = Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height));
        tilt.classList.add("is-hover");
        card.classList.add("is-tilting");
        card.style.setProperty("--tilt-ry", `${((px - 0.5) * 7).toFixed(2)}deg`);
        card.style.setProperty("--tilt-rx", `${((0.5 - py) * 7).toFixed(2)}deg`);
        card.style.setProperty("--tilt-gx", `${(px * 100).toFixed(1)}%`);
        card.style.setProperty("--tilt-gy", `${(py * 100).toFixed(1)}%`);
      };
      const reset = () => {
        tilt.classList.remove("is-hover");
        card.classList.remove("is-tilting");
        card.style.setProperty("--tilt-rx", "0deg");
        card.style.setProperty("--tilt-ry", "0deg");
      };
      tilt.addEventListener("pointermove", track);
      tilt.addEventListener("pointerleave", reset);
      tilt.addEventListener("pointercancel", reset);
      cleanups.push(() => tilt.removeEventListener("pointermove", track));
      cleanups.push(() => tilt.removeEventListener("pointerleave", reset));
      cleanups.push(() => tilt.removeEventListener("pointercancel", reset));
    });

    const revealItems = document.querySelectorAll<HTMLElement>(".reveal-on-scroll");
    let observer: IntersectionObserver | undefined;
    if ("IntersectionObserver" in window && !reduceMotion.matches) {
      observer = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add("is-visible");
            observer?.unobserve(entry.target);
          });
        },
        { threshold: 0.14, rootMargin: "0px 0px -6% 0px" },
      );
      revealItems.forEach((item) => observer?.observe(item));
    } else {
      revealItems.forEach((item) => item.classList.add("is-visible"));
    }

    let scrollFrame = 0;
    const updateScroll = () => {
      const maxScroll = Math.max(1, root.scrollHeight - window.innerHeight);
      root.style.setProperty(
        "--scroll-progress",
        String(Math.min(1, Math.max(0, window.scrollY / maxScroll))),
      );
      if (!reduceMotion.matches) {
        root.style.setProperty("--glow-shift", `${Math.min(window.scrollY * 0.055, 90)}px`);
      }
      scrollFrame = 0;
    };
    const handleScroll = () => {
      if (!scrollFrame) scrollFrame = requestAnimationFrame(updateScroll);
    };
    window.addEventListener("scroll", handleScroll, { passive: true });
    updateScroll();

    return () => {
      cancelAnimationFrame(frame);
      if (scrollFrame) cancelAnimationFrame(scrollFrame);
      window.removeEventListener("scroll", handleScroll);
      observer?.disconnect();
      timers.forEach(clearTimeout);
      cleanups.forEach((cleanup) => cleanup());
      body.classList.remove("is-ready", "nav-open");
    };
  }, []);

  return null;
}
