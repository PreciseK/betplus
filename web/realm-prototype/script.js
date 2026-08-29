(() => {
  const root = document.documentElement;
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
  const themeToggle = document.querySelector("#theme-toggle");
  const soundToggle = document.querySelector("#sound-toggle");
  const journeyFill = document.querySelector("#journey-fill");
  let audioEnabled = false;
  let audioContext;

  const systemLight = window.matchMedia("(prefers-color-scheme: light)").matches;
  const storedTheme = localStorage.getItem("betplus-realm-theme");
  setTheme(storedTheme || (systemLight ? "light" : "dark"));

  themeToggle.addEventListener("click", () => {
    const next = root.dataset.theme === "dark" ? "light" : "dark";
    setTheme(next);
    localStorage.setItem("betplus-realm-theme", next);
  });

  function setTheme(theme) {
    root.dataset.theme = theme;
    themeToggle.setAttribute("aria-pressed", String(theme === "light"));
    themeToggle.querySelector(".tool-label").textContent = theme === "light" ? "Dusk" : "Morning";
    themeToggle.setAttribute("aria-label", theme === "light" ? "Switch to dusk theme" : "Switch to morning theme");
    document.querySelector('meta[name="theme-color"]').content = theme === "light" ? "#FBF6EC" : "#15100A";
  }

  soundToggle.addEventListener("click", () => {
    audioEnabled = !audioEnabled;
    soundToggle.setAttribute("aria-pressed", String(audioEnabled));
    soundToggle.querySelector(".tool-label").textContent = audioEnabled ? "Sound on" : "Sound off";
    soundToggle.querySelector(".tool-icon").textContent = audioEnabled ? "♫" : "♪";
    soundToggle.setAttribute("aria-label", audioEnabled ? "Turn sound off" : "Turn sound on");
    if (audioEnabled) {
      audioContext ||= new (window.AudioContext || window.webkitAudioContext)();
      playTone("toggle");
    }
  });

  function playTone(kind) {
    if (!audioEnabled || !audioContext) return;
    const now = audioContext.currentTime;
    const osc = audioContext.createOscillator();
    const gain = audioContext.createGain();
    osc.connect(gain).connect(audioContext.destination);
    if (kind === "place") {
      osc.type = "sine";
      osc.frequency.setValueAtTime(196, now);
      osc.frequency.exponentialRampToValueAtTime(294, now + .12);
      gain.gain.setValueAtTime(.0001, now);
      gain.gain.exponentialRampToValueAtTime(.075, now + .018);
      gain.gain.exponentialRampToValueAtTime(.0001, now + .2);
      osc.start(now); osc.stop(now + .21);
    } else if (kind === "flip") {
      osc.type = "triangle";
      osc.frequency.setValueAtTime(92, now);
      osc.frequency.exponentialRampToValueAtTime(220, now + .16);
      gain.gain.setValueAtTime(.0001, now);
      gain.gain.exponentialRampToValueAtTime(.09, now + .01);
      gain.gain.exponentialRampToValueAtTime(.0001, now + .22);
      osc.start(now); osc.stop(now + .23);
    } else {
      osc.type = "sine";
      osc.frequency.value = 280;
      gain.gain.setValueAtTime(.035, now);
      gain.gain.exponentialRampToValueAtTime(.0001, now + .08);
      osc.start(now); osc.stop(now + .09);
    }
  }

  // Reveal each place as it enters the journey. Content stays visible without IntersectionObserver.
  document.querySelectorAll(".reveal-group").forEach((group) => {
    if (!("IntersectionObserver" in window) || reduceMotion.matches) {
      group.classList.add("is-visible");
      return;
    }
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add("is-visible");
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: .15 });
    observer.observe(group);
  });

  // Scroll progress and place marker.
  const places = [...document.querySelectorAll(".place")];
  const journeyLinks = [...document.querySelectorAll("[data-journey]")];
  function updateJourney() {
    const scrollable = document.documentElement.scrollHeight - window.innerHeight;
    const progress = scrollable > 0 ? Math.min(1, window.scrollY / scrollable) : 0;
    if (window.innerWidth >= 1180) journeyFill.style.height = `${progress * 100}%`;
    else journeyFill.style.width = `${progress * 100}%`;
    const probe = window.scrollY + window.innerHeight * .4;
    let active = places[0]?.id;
    places.forEach((place) => { if (place.offsetTop <= probe) active = place.id; });
    journeyLinks.forEach((link) => {
      const isActive = link.dataset.journey === active;
      link.classList.toggle("is-active", isActive);
      if (isActive) link.setAttribute("aria-current", "location");
      else link.removeAttribute("aria-current");
    });
  }
  addEventListener("scroll", updateJourney, { passive: true });
  addEventListener("resize", updateJourney);
  updateJourney();

  // Gate parallax. Device orientation is requested only after the visitor elects to enter.
  const gate = document.querySelector("#gate");
  const parallaxLayers = [...gate.querySelectorAll("[data-depth]")];
  function applyParallax(x, y) {
    if (reduceMotion.matches) return;
    parallaxLayers.forEach((layer) => {
      const depth = Number(layer.dataset.depth);
      layer.style.setProperty("--px", `${x * depth}px`);
      layer.style.setProperty("--py", `${y * depth}px`);
    });
  }
  gate.addEventListener("pointermove", (event) => {
    if (event.pointerType === "touch") return;
    const x = (event.clientX / innerWidth - .5) * 30;
    const y = (event.clientY / innerHeight - .5) * 24;
    applyParallax(x, y);
  });
  gate.addEventListener("pointerleave", () => applyParallax(0, 0));

  document.querySelector("#enter-realm").addEventListener("click", async () => {
    if (reduceMotion.matches || !("DeviceOrientationEvent" in window)) return;
    try {
      if (typeof DeviceOrientationEvent.requestPermission === "function") {
        const permission = await DeviceOrientationEvent.requestPermission();
        if (permission !== "granted") return;
      }
      addEventListener("deviceorientation", (event) => {
        const x = Math.max(-16, Math.min(16, event.gamma || 0));
        const y = Math.max(-12, Math.min(12, (event.beta || 0) - 40));
        applyParallax(x, y);
      }, { passive: true });
    } catch (_) {
      // Motion permission is optional; the realm remains fully usable without it.
    }
  });

  // Path choice on tap mirrors desktop hover without trapping navigation.
  const pathDoors = document.querySelector("#path-doors");
  pathDoors.querySelectorAll(".path-door").forEach((door) => {
    door.addEventListener("pointerdown", () => {
      pathDoors.dataset.chosen = door.dataset.path;
    });
  });

  // Regalia placement uses abstract, review-gated layers only.
  const placed = new Set();
  const regaliaButtons = [...document.querySelectorAll(".regalia-piece")];
  const placedCount = document.querySelector("#placed-count");
  regaliaButtons.forEach((button) => {
    button.setAttribute("aria-pressed", "false");
    button.addEventListener("click", () => {
      const slot = button.dataset.slot;
      document.querySelectorAll(`.regalia-piece[data-slot="${slot}"]`).forEach((peer) => peer.setAttribute("aria-pressed", "false"));
      button.setAttribute("aria-pressed", "true");
      const layer = document.querySelector(`[data-layer="${slot}"]`);
      layer.classList.remove("is-placed");
      requestAnimationFrame(() => requestAnimationFrame(() => layer.classList.add("is-placed")));
      placed.add(slot);
      placedCount.textContent = `${placed.size} ${placed.size === 1 ? "form" : "forms"} placed`;
      document.querySelector("#regalia-name").textContent = button.dataset.name;
      document.querySelector("#regalia-origin").textContent = button.dataset.origin;
      document.querySelector("#regalia-context").textContent = button.dataset.context;
      playTone("place");
      if ("vibrate" in navigator) navigator.vibrate(10);
    });
  });
  document.querySelector("#clear-regalia").addEventListener("click", () => {
    placed.clear();
    document.querySelectorAll(".regalia-layer").forEach((layer) => layer.classList.remove("is-placed"));
    regaliaButtons.forEach((button) => button.setAttribute("aria-pressed", "false"));
    placedCount.textContent = "0 forms placed";
    document.querySelector("#regalia-name").textContent = "Choose an abstract study";
    document.querySelector("#regalia-origin").textContent = "Seven traditions are represented as review-gated references.";
    document.querySelector("#regalia-context").textContent = "Prototype policy: specific depictions and extended cultural claims remain sealed until an advisor approval reference is connected.";
  });

  // Codex catalogue. Featured entries mirror the project's preview-only catalogue.
  const catalogue = {
    7: ["Beaded crown study", "Yoruba · Head form", "A sacred royal form represented abstractly pending recorded advisory approval."],
    14: ["Coral collar study", "Edo (Benin) · Neck form", "Coral is associated with royal identity and must be depicted with care."],
    23: ["Royal wrapper study", "Igbo · Waist form", "A layered textile placeholder awaiting a cleared tradition-specific treatment."],
    31: ["Ceremonial turban study", "Hausa–Fulani · Head form", "A structured head form shown without reference to a living ruler."],
    42: ["Court necklace study", "Efik–Ibibio · Neck form", "A neutral placeholder awaiting advisor-supplied naming and description."],
    55: ["Royal staff study", "Ijaw · Hand form", "A symbol-of-office silhouette with no palace-specific marks."],
    63: ["Embroidered robe study", "Middle Belt · Torso form", "The final tradition-specific treatment requires recorded sign-off."],
    74: ["Beaded wristwear study", "Across Nigeria · Wrist form", "Origin-specific naming remains sealed until approved catalogue content is connected."],
    88: ["Royal sandal study", "Across Nigeria · Foot form", "A footwear layer represented without copying existing palace regalia."]
  };
  const codexGrid = document.querySelector("#codex-grid");
  const codexDetail = document.querySelector("#codex-detail");
  for (let number = 1; number <= 90; number += 1) {
    const button = document.createElement("button");
    const featured = catalogue[number];
    button.type = "button";
    button.className = `codex-cell${featured ? " is-featured" : ""}`;
    button.setAttribute("aria-label", featured ? `Catalogue ${number}: ${featured[0]}` : `Catalogue ${number}: sealed pending review`);
    button.innerHTML = `<span>${String(number).padStart(2, "0")}</span>`;
    button.addEventListener("click", () => {
      codexGrid.querySelectorAll(".codex-cell").forEach((cell) => cell.classList.remove("is-active"));
      button.classList.add("is-active");
      const detail = featured || ["Reserved catalogue position", "Origin withheld", "This position remains unpublished until its name, origin and depiction receive recorded cultural-advisor sign-off."];
      codexDetail.innerHTML = `<span class="codex-number">No. ${String(number).padStart(2, "0")}</span><h3>${detail[0]}</h3><p class="codex-origin">${detail[1]}</p><p>${detail[2]}</p><span class="review-status">Preview silhouette · not cleared for publication</span>`;
    });
    codexGrid.append(button);
  }

  // Free BlackRed demo. Outcome is generated once when the card is turned.
  let chosenColour = null;
  let resolving = false;
  const colourButtons = [...document.querySelectorAll(".colour-choice")];
  const dealButton = document.querySelector("#deal-card");
  const card = document.querySelector("#playing-card");
  const cardFront = document.querySelector("#card-front");
  const result = document.querySelector("#duel-result");

  colourButtons.forEach((button) => {
    button.addEventListener("click", () => {
      if (resolving) return;
      chosenColour = button.dataset.colour;
      colourButtons.forEach((peer) => peer.setAttribute("aria-pressed", String(peer === button)));
      dealButton.disabled = false;
      dealButton.textContent = card.classList.contains("is-flipped") ? "Turn another card" : "Turn the card";
    });
  });

  dealButton.addEventListener("click", () => {
    if (!chosenColour || resolving) return;
    resolving = true;
    dealButton.disabled = true;
    card.classList.remove("is-flipped", "is-settled");
    result.classList.remove("is-outcome");
    result.innerHTML = "<span>The card is turning…</span><strong>Odds 1 in 2 · 1.85×</strong>";
    const random = new Uint32Array(1);
    crypto.getRandomValues(random);
    const drawn = random[0] % 2 === 0 ? "Black" : "Red";
    cardFront.classList.toggle("is-black", drawn === "Black");
    cardFront.querySelector(".card-suit").textContent = drawn === "Black" ? "♠" : "◆";
    cardFront.querySelector(".card-colour-label").textContent = drawn;
    playTone("flip");
    requestAnimationFrame(() => card.classList.add("is-flipped"));
    card.scrollIntoView({ behavior: reduceMotion.matches ? "auto" : "smooth", block: "center" });
    const duration = reduceMotion.matches ? 260 : 720;
    setTimeout(() => {
      card.classList.add("is-settled");
      const matched = chosenColour === drawn;
      result.classList.add("is-outcome");
      result.innerHTML = `<span>${matched ? "Your call matched." : "Your call did not match."} Draw: ${drawn}.</span><strong>Odds 1 in 2 · 1.85×</strong>`;
      dealButton.disabled = false;
      dealButton.textContent = "Turn another card";
      resolving = false;
    }, duration);
  });

  // Magnetic motion is capped at 6px and never runs for touch or reduced motion.
  document.querySelectorAll(".magnetic").forEach((button) => {
    button.addEventListener("pointermove", (event) => {
      if (event.pointerType === "touch" || reduceMotion.matches) return;
      const rect = button.getBoundingClientRect();
      const x = Math.max(-6, Math.min(6, (event.clientX - rect.left - rect.width / 2) * .12));
      const y = Math.max(-6, Math.min(6, (event.clientY - rect.top - rect.height / 2) * .12));
      button.style.transform = `translate(${x}px, ${y}px)`;
    });
    button.addEventListener("pointerleave", () => { button.style.transform = ""; });
  });
})();
