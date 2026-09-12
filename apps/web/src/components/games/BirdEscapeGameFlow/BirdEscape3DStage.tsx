"use client";

import React, { useEffect, useRef } from "react";
import * as THREE from "three";

interface BirdEscape3DStageProps {
  roundPhase: "betting" | "flying" | "crashed";
  multiplier: number;
}

interface BirdInstance {
  meshGroup: THREE.Group;
  leftWing: THREE.Mesh;
  rightWing: THREE.Mesh;
  homePos: THREE.Vector3;
  homeRot: THREE.Euler;
  state: "in_cage" | "escaping" | "crash_escaping" | "escaped";
  escapeStartTime: number;
  perchSeed: number;
  crashVel?: THREE.Vector3;
}

export function BirdEscape3DStage({ roundPhase, multiplier }: BirdEscape3DStageProps) {
  const mountRef = useRef<HTMLDivElement>(null);
  const sceneRef = useRef<THREE.Scene | null>(null);
  const cameraRef = useRef<THREE.PerspectiveCamera | null>(null);
  const rendererRef = useRef<THREE.WebGLRenderer | null>(null);

  // References to 3D elements
  const birdsRef = useRef<BirdInstance[]>([]);
  const cageDoorRef = useRef<THREE.Group | null>(null);
  const particlesRef = useRef<THREE.Points | null>(null);
  const particlePositionsRef = useRef<Float32Array | null>(null);
  const cageGroupRef = useRef<THREE.Group | null>(null);
  const goldPointLightRef = useRef<THREE.PointLight | null>(null);

  // State refs for animation loop
  const phaseRef = useRef(roundPhase);
  const multiplierRef = useRef(multiplier);
  const lastEscapedCountRef = useRef(0);
  const crashStartTimeRef = useRef<number | null>(null);

  useEffect(() => {
    phaseRef.current = roundPhase;
  }, [roundPhase]);

  useEffect(() => {
    multiplierRef.current = multiplier;
  }, [multiplier]);

  useEffect(() => {
    const container = mountRef.current;
    if (!container) return;

    // WebGL context creation can legitimately fail — disabled GPU, an embedded
    // webview, an older browser, or (as found in this project's own test suite) a
    // jsdom test environment with no GPU at all. Failing here must not crash the
    // whole game; the parent's 2D mode remains available regardless.
    let renderer: THREE.WebGLRenderer;
    try {
      renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    } catch (error) {
      console.warn("BirdEscape3DStage: WebGL unavailable, 3D stage disabled.", error);
      return;
    }

    // 1. Scene
    const scene = new THREE.Scene();
    sceneRef.current = scene;
    scene.fog = new THREE.FogExp2(0x06140e, 0.035);

    // 2. Camera: Centered and framed on the cage
    const camera = new THREE.PerspectiveCamera(
      42,
      container.clientWidth / container.clientHeight,
      0.1,
      100
    );
    camera.position.set(0, 1.6, 6.8);
    camera.lookAt(0, 1.1, 0);
    cameraRef.current = camera;

    // 3. Renderer
    renderer.setSize(container.clientWidth, container.clientHeight);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.25;
    rendererRef.current = renderer;
    container.appendChild(renderer.domElement);

    // 4. Lights
    const ambientLight = new THREE.AmbientLight(0x0d3826, 1.8);
    scene.add(ambientLight);

    const moonLight = new THREE.DirectionalLight(0x8ae8c0, 2.0);
    moonLight.position.set(4, 7, 4);
    scene.add(moonLight);

    const goldPointLight = new THREE.PointLight(0xf5b731, 3.2, 8);
    goldPointLight.position.set(0, 1.2, 0);
    scene.add(goldPointLight);
    goldPointLightRef.current = goldPointLight;

    // Materials
    const goldMaterial = new THREE.MeshStandardMaterial({
      color: 0xf5b731,
      metalness: 0.85,
      roughness: 0.22,
      emissive: 0x4a3205,
      emissiveIntensity: 0.2,
    });

    const darkPerchMaterial = new THREE.MeshStandardMaterial({
      color: 0x1d4734,
      metalness: 0.5,
      roughness: 0.5,
    });

    // 5 Vibrant Songbird Color Palettes: Canary Gold, Emerald Jade, Sapphire Azure, Ruby Cardinal, Amethyst Violet
    const BIRD_COLOR_PALETTES = [
      // 1. Canary Gold
      {
        body: new THREE.MeshStandardMaterial({
          color: 0xffcb47,
          metalness: 0.35,
          roughness: 0.3,
          emissive: 0xf59e0b,
          emissiveIntensity: 0.35,
        }),
        wing: new THREE.MeshStandardMaterial({
          color: 0xffe082,
          metalness: 0.4,
          roughness: 0.2,
          emissive: 0xf5b731,
          emissiveIntensity: 0.45,
          side: THREE.DoubleSide,
        }),
      },
      // 2. Emerald Jade
      {
        body: new THREE.MeshStandardMaterial({
          color: 0x10b981,
          metalness: 0.35,
          roughness: 0.3,
          emissive: 0x059669,
          emissiveIntensity: 0.35,
        }),
        wing: new THREE.MeshStandardMaterial({
          color: 0x6ee7b7,
          metalness: 0.4,
          roughness: 0.2,
          emissive: 0x10b981,
          emissiveIntensity: 0.45,
          side: THREE.DoubleSide,
        }),
      },
      // 3. Sapphire Azure
      {
        body: new THREE.MeshStandardMaterial({
          color: 0x3b82f6,
          metalness: 0.35,
          roughness: 0.3,
          emissive: 0x1d4ed8,
          emissiveIntensity: 0.35,
        }),
        wing: new THREE.MeshStandardMaterial({
          color: 0x93c5fd,
          metalness: 0.4,
          roughness: 0.2,
          emissive: 0x3b82f6,
          emissiveIntensity: 0.45,
          side: THREE.DoubleSide,
        }),
      },
      // 4. Ruby Cardinal
      {
        body: new THREE.MeshStandardMaterial({
          color: 0xef4444,
          metalness: 0.35,
          roughness: 0.3,
          emissive: 0xb91c1c,
          emissiveIntensity: 0.35,
        }),
        wing: new THREE.MeshStandardMaterial({
          color: 0xfca5a5,
          metalness: 0.4,
          roughness: 0.2,
          emissive: 0xef4444,
          emissiveIntensity: 0.45,
          side: THREE.DoubleSide,
        }),
      },
      // 5. Amethyst Violet
      {
        body: new THREE.MeshStandardMaterial({
          color: 0xa855f7,
          metalness: 0.35,
          roughness: 0.3,
          emissive: 0x7e22ce,
          emissiveIntensity: 0.35,
        }),
        wing: new THREE.MeshStandardMaterial({
          color: 0xd8b4fe,
          metalness: 0.4,
          roughness: 0.2,
          emissive: 0xa855f7,
          emissiveIntensity: 0.45,
          side: THREE.DoubleSide,
        }),
      },
    ];

    // 5. Build 3D Golden Birdcage
    const cageGroup = new THREE.Group();
    cageGroupRef.current = cageGroup;

    // Cage Base & Ring
    const baseGeo = new THREE.CylinderGeometry(1.65, 1.7, 0.14, 32);
    const cageBase = new THREE.Mesh(baseGeo, goldMaterial);
    cageBase.position.y = -0.4;
    cageGroup.add(cageBase);

    // Cage Dome Top
    const domeGeo = new THREE.SphereGeometry(1.65, 32, 16, 0, Math.PI * 2, 0, Math.PI / 2);
    const dome = new THREE.Mesh(domeGeo, new THREE.MeshStandardMaterial({
      ...goldMaterial,
      wireframe: true,
      wireframeLinewidth: 2,
    }));
    dome.position.y = 1.9;
    cageGroup.add(dome);

    // Top Hook Ring
    const hookGeo = new THREE.TorusGeometry(0.32, 0.06, 16, 32);
    const hook = new THREE.Mesh(hookGeo, goldMaterial);
    hook.position.y = 3.8;
    cageGroup.add(hook);

    // Cage Vertical Bars
    const barCount = 16;
    const barGeo = new THREE.CylinderGeometry(0.025, 0.025, 2.4, 8);
    for (let i = 0; i < barCount; i++) {
      const angle = (i / barCount) * Math.PI * 2;
      // Front opening for door (between 0.4 and 1.1 rad)
      if (angle > 0.35 && angle < 1.15) continue;

      const bar = new THREE.Mesh(barGeo, goldMaterial);
      bar.position.set(Math.cos(angle) * 1.6, 0.75, Math.sin(angle) * 1.6);
      cageGroup.add(bar);
    }

    // Cage Horizontal Ring Bands
    const ringGeo = new THREE.TorusGeometry(1.62, 0.035, 12, 36);
    const ring1 = new THREE.Mesh(ringGeo, goldMaterial);
    ring1.rotation.x = Math.PI / 2;
    ring1.position.y = 0.4;
    cageGroup.add(ring1);

    const ring2 = new THREE.Mesh(ringGeo, goldMaterial);
    ring2.rotation.x = Math.PI / 2;
    ring2.position.y = 1.4;
    cageGroup.add(ring2);

    // Multiple Perches inside cage across tiers
    const perchGeo = new THREE.CylinderGeometry(0.035, 0.035, 2.1, 8);
    // Lower perch
    const perchLower = new THREE.Mesh(perchGeo, darkPerchMaterial);
    perchLower.rotation.z = Math.PI / 2;
    perchLower.position.set(0, 0.15, -0.2);
    cageGroup.add(perchLower);

    // Higher perch
    const perchHigher = new THREE.Mesh(perchGeo, darkPerchMaterial);
    perchHigher.rotation.z = Math.PI / 2;
    perchHigher.rotation.y = 0.4;
    perchHigher.position.set(0.1, 0.85, 0.2);
    cageGroup.add(perchHigher);

    // Upper dome tier perch
    const perchUpper = new THREE.Mesh(perchGeo, darkPerchMaterial);
    perchUpper.rotation.z = Math.PI / 2;
    perchUpper.rotation.y = -0.3;
    perchUpper.position.set(-0.1, 1.25, -0.1);
    cageGroup.add(perchUpper);

    // Cage Door (Open door at the front-right opening)
    const doorGroup = new THREE.Group();
    doorGroup.position.set(Math.cos(1.15) * 1.6, 0, Math.sin(1.15) * 1.6);
    const doorBarGeo = new THREE.CylinderGeometry(0.025, 0.025, 1.9, 8);
    const doorBar1 = new THREE.Mesh(doorBarGeo, goldMaterial);
    doorBar1.position.set(-0.35, 0.75, 0);
    doorGroup.add(doorBar1);
    const doorBar2 = new THREE.Mesh(doorBarGeo, goldMaterial);
    doorBar2.position.set(0, 0.75, 0);
    doorGroup.add(doorBar2);
    doorGroup.rotation.y = 0.9; // Open door
    cageDoorRef.current = doorGroup;
    cageGroup.add(doorGroup);

    scene.add(cageGroup);

    // 6. Build Flock of 25 Birds inside the cage with 5 Color Variations
    const totalBirds = 25;
    const birdsList: BirdInstance[] = [];

    // Predefined 25 distinct perching spots inside the cage across tiers
    const perchSpots = [
      // Lower tier (y ~ -0.2 to -0.15)
      { x: -0.7, y: -0.2, z: -0.4, rotY: 0.5 },
      { x: -0.4, y: -0.2, z: 0.4, rotY: 0.1 },
      { x: 0.0, y: -0.2, z: -0.6, rotY: -0.2 },
      { x: 0.1, y: -0.2, z: 0.5, rotY: -0.4 },
      { x: 0.6, y: -0.2, z: 0.3, rotY: 0.3 },
      { x: 0.7, y: -0.2, z: -0.5, rotY: -0.6 },
      { x: -0.2, y: -0.15, z: 0.0, rotY: 0.8 },

      // Mid-lower perch bar (y ~ 0.35)
      { x: -0.8, y: 0.35, z: -0.2, rotY: 0.3 },
      { x: -0.5, y: 0.35, z: -0.2, rotY: 0.1 },
      { x: -0.2, y: 0.35, z: -0.2, rotY: -0.1 },
      { x: 0.1, y: 0.35, z: -0.2, rotY: 0.2 },
      { x: 0.4, y: 0.35, z: -0.2, rotY: 0.4 },
      { x: 0.7, y: 0.35, z: -0.2, rotY: -0.3 },

      // Mid-upper perch bar (y ~ 0.7 to 0.85)
      { x: -0.6, y: 0.75, z: -0.4, rotY: 0.4 },
      { x: -0.3, y: 0.7, z: -0.5, rotY: 0.2 },
      { x: 0.0, y: 0.85, z: 0.2, rotY: -0.2 },
      { x: 0.3, y: 0.85, z: 0.2, rotY: -0.4 },
      { x: 0.6, y: 0.85, z: 0.2, rotY: -0.5 },
      { x: 0.4, y: 0.7, z: -0.5, rotY: -0.3 },

      // Upper dome tier (y ~ 1.15 to 1.35)
      { x: -0.5, y: 1.15, z: 0.1, rotY: 0.6 },
      { x: -0.2, y: 1.25, z: 0.2, rotY: 0.2 },
      { x: 0.2, y: 1.25, z: 0.1, rotY: -0.3 },
      { x: 0.5, y: 1.15, z: 0.0, rotY: -0.5 },
      { x: -0.1, y: 1.35, z: -0.3, rotY: 0.1 },
      { x: 0.3, y: 1.35, z: -0.3, rotY: -0.2 },
    ];

    for (let i = 0; i < totalBirds; i++) {
      const spot = perchSpots[i % perchSpots.length];
      const palette = BIRD_COLOR_PALETTES[i % BIRD_COLOR_PALETTES.length];
      const birdGroup = new THREE.Group();

      // Body
      const bodyGeo = new THREE.ConeGeometry(0.14, 0.45, 12);
      const body = new THREE.Mesh(bodyGeo, palette.body);
      body.rotation.x = Math.PI / 2 + 0.2;
      birdGroup.add(body);

      // Head
      const headGeo = new THREE.SphereGeometry(0.11, 12, 12);
      const head = new THREE.Mesh(headGeo, palette.body);
      head.position.set(0, 0.09, 0.22);
      birdGroup.add(head);

      // Beak
      const beakGeo = new THREE.ConeGeometry(0.04, 0.12, 8);
      const beak = new THREE.Mesh(beakGeo, new THREE.MeshStandardMaterial({ color: 0xff9100, roughness: 0.2 }));
      beak.rotation.x = Math.PI / 2;
      beak.position.set(0, 0.08, 0.34);
      birdGroup.add(beak);

      // Wings
      const wingShape = new THREE.Shape();
      wingShape.moveTo(0, 0);
      wingShape.quadraticCurveTo(0.4, 0.2, 0.6, 0.04);
      wingShape.quadraticCurveTo(0.35, -0.18, 0, 0);
      const wingGeo = new THREE.ShapeGeometry(wingShape);

      const leftWing = new THREE.Mesh(wingGeo, palette.wing);
      leftWing.position.set(0.09, 0.06, 0.06);
      leftWing.rotation.set(0, 0, 0.15);
      birdGroup.add(leftWing);

      const rightWing = new THREE.Mesh(wingGeo, palette.wing);
      rightWing.position.set(-0.09, 0.06, 0.06);
      rightWing.scale.x = -1;
      rightWing.rotation.set(0, 0, -0.15);
      birdGroup.add(rightWing);

      // Initial placement
      const homePos = new THREE.Vector3(spot.x, spot.y, spot.z);
      const homeRot = new THREE.Euler(0, spot.rotY, 0);
      birdGroup.position.copy(homePos);
      birdGroup.rotation.copy(homeRot);

      scene.add(birdGroup);

      birdsList.push({
        meshGroup: birdGroup,
        leftWing,
        rightWing,
        homePos,
        homeRot,
        state: "in_cage",
        escapeStartTime: 0,
        perchSeed: Math.random() * 10,
      });
    }

    birdsRef.current = birdsList;

    // 7. Ambient Forest Fireflies Particles
    const pCount = 120;
    const pGeo = new THREE.BufferGeometry();
    const pPositions = new Float32Array(pCount * 3);
    for (let i = 0; i < pCount * 3; i += 3) {
      pPositions[i] = (Math.random() - 0.5) * 14;
      pPositions[i + 1] = Math.random() * 7 - 1;
      pPositions[i + 2] = (Math.random() - 0.5) * 12;
    }
    pGeo.setAttribute("position", new THREE.BufferAttribute(pPositions, 3));
    particlePositionsRef.current = pPositions;

    const pMat = new THREE.PointsMaterial({
      color: 0xf5b731,
      size: 0.07,
      transparent: true,
      opacity: 0.6,
      blending: THREE.AdditiveBlending,
    });
    const particles = new THREE.Points(pGeo, pMat);
    particlesRef.current = particles;
    scene.add(particles);

    // 8. Resize Listener
    const handleResize = () => {
      if (!container || !camera || !renderer) return;
      camera.aspect = container.clientWidth / container.clientHeight;
      camera.updateProjectionMatrix();
      renderer.setSize(container.clientWidth, container.clientHeight);
    };
    window.addEventListener("resize", handleResize);

    // 9. Animation Loop
    const startEpoch = performance.now();
    let animId: number;

    const animate = () => {
      animId = requestAnimationFrame(animate);
      const elapsed = (performance.now() - startEpoch) * 0.001;

      const phase = phaseRef.current;
      const mult = multiplierRef.current;

      // Animate ambient fireflies
      if (particlesRef.current && particlePositionsRef.current) {
        const positions = particlesRef.current.geometry.attributes.position.array as Float32Array;
        for (let i = 1; i < pCount * 3; i += 3) {
          positions[i] += Math.sin(elapsed + i) * 0.002;
        }
        particlesRef.current.geometry.attributes.position.needsUpdate = true;
      }

      // CRASH TRIGGER DETECTION:
      // When the round crashes, record crash start and burst all remaining birds out in panic
      if (phase === "crashed") {
        if (crashStartTimeRef.current === null) {
          crashStartTimeRef.current = elapsed;
          birdsRef.current.forEach((bird, index) => {
            if (bird.state === "in_cage") {
              bird.state = "crash_escaping";
              bird.escapeStartTime = elapsed;
              // Radial outward trajectory with upward burst
              const angle = (index / totalBirds) * Math.PI * 2 + ((index * 1.7) % 1.2) - 0.6;
              const horizSpeed = 3.2 + (index % 5) * 0.7;
              const vertSpeed = 3.6 + (index % 3) * 0.9;
              bird.crashVel = new THREE.Vector3(
                Math.cos(angle) * horizSpeed,
                vertSpeed,
                Math.sin(angle) * horizSpeed
              );
            }
          });
        }
      }

      // RESET ON BETTING PHASE:
      if (phase === "betting" && (crashStartTimeRef.current !== null || lastEscapedCountRef.current > 0)) {
        crashStartTimeRef.current = null;
        lastEscapedCountRef.current = 0;
        if (cageGroupRef.current) {
          cageGroupRef.current.position.set(0, 0, 0);
          cageGroupRef.current.rotation.set(0, 0, 0);
        }
        if (cageDoorRef.current) {
          cageDoorRef.current.rotation.y = 0.9;
        }
        birdsRef.current.forEach((b) => {
          b.state = "in_cage";
          b.meshGroup.position.copy(b.homePos);
          b.meshGroup.rotation.copy(b.homeRot);
          b.meshGroup.scale.set(1, 1, 1);
          b.leftWing.rotation.set(0, 0, 0.15);
          b.rightWing.rotation.set(0, 0, -0.15);
        });
      }

      // CAGE BEHAVIOR:
      // When crashed: Cage drops and falls rapidly with gravity acceleration, tilt, and impact shake
      if (cageGroupRef.current) {
        if (phase === "crashed" && crashStartTimeRef.current !== null) {
          const crashT = elapsed - crashStartTimeRef.current;
          // Gravity acceleration downward: y = -0.5 * g * t^2
          const fallDistance = 0.5 * 24 * Math.pow(crashT, 2);
          cageGroupRef.current.position.y = -fallDistance;
          // Dramatic tilt and roll as it drops
          cageGroupRef.current.rotation.z = -Math.min(1.2, crashT * 1.8);
          cageGroupRef.current.rotation.x = Math.min(0.6, crashT * 1.2);
          cageGroupRef.current.rotation.y += 0.02;

          // Door swings open violently during fall
          if (cageDoorRef.current) {
            cageDoorRef.current.rotation.y = 1.4 + Math.sin(crashT * 14) * 0.4;
          }

          // Screen impact camera shake during the initial severance/drop
          const shake = Math.max(0, 0.18 * (1 - crashT / 0.6));
          camera.position.x = (Math.random() - 0.5) * shake;
          camera.position.y = 1.6 + (Math.random() - 0.5) * shake;
        } else {
          // Normal gentle hover / sway
          cageGroupRef.current.position.y = Math.sin(elapsed * 1.5) * 0.03;
          cageGroupRef.current.position.x = 0;
          cageGroupRef.current.rotation.y = Math.sin(elapsed * 0.5) * 0.02;
          cageGroupRef.current.rotation.x = 0;
          cageGroupRef.current.rotation.z = 0;
          if (cageDoorRef.current) {
            cageDoorRef.current.rotation.y = 0.9;
          }
          camera.position.x = Math.sin(elapsed * 0.3) * 0.15;
          camera.position.y = 1.6 + Math.cos(elapsed * 0.2) * 0.08;
        }
      }

      // PROGRESSIVE BIRD ESCAPE MECHANIC DURING FLIGHT:
      const escapedCount = phase === "betting" ? 0 : Math.floor(mult);

      birdsRef.current.forEach((bird, index) => {
        // Trigger progressive escape during flight if index < escapedCount
        if (phase === "flying" && index < escapedCount && bird.state === "in_cage") {
          bird.state = "escaping";
          bird.escapeStartTime = elapsed;
          lastEscapedCountRef.current = escapedCount;
        }

        if (bird.state === "in_cage") {
          // Gentle perching, breathing, pecking motion
          const hop = Math.sin(elapsed * 2.5 + bird.perchSeed) * 0.015;
          const headTurn = Math.sin(elapsed * 1.2 + bird.perchSeed) * 0.2;
          bird.meshGroup.position.set(bird.homePos.x, bird.homePos.y + hop, bird.homePos.z);
          bird.meshGroup.rotation.set(0, bird.homeRot.y + headTurn, 0);

          // Wings rest folded
          bird.leftWing.rotation.z = 0.15 + Math.sin(elapsed * 2 + bird.perchSeed) * 0.03;
          bird.rightWing.rotation.z = -0.15 - Math.sin(elapsed * 2 + bird.perchSeed) * 0.03;
        } else if (bird.state === "escaping") {
          // Progressive escape during flight: out the door and into the sky
          const flightTime = elapsed - bird.escapeStartTime;
          const flapSpeed = 22;
          bird.leftWing.rotation.z = Math.sin(flightTime * flapSpeed) * 0.7;
          bird.rightWing.rotation.z = -Math.sin(flightTime * flapSpeed) * 0.7;

          // Door opening coordinates: exit towards (1.6, 0.8, 1.2) then soar upward into distance
          const exitT = Math.min(flightTime / 0.7, 1);
          const soarT = Math.max(0, flightTime - 0.7);

          // Phase 1: Fly from home position out through the cage door
          const exitX = THREE.MathUtils.lerp(bird.homePos.x, 1.8, exitT);
          const exitY = THREE.MathUtils.lerp(bird.homePos.y, 1.2, exitT);
          const exitZ = THREE.MathUtils.lerp(bird.homePos.z, 1.4, exitT);

          // Phase 2: Soar upward into the sky and fade into distance
          const finalX = exitX + Math.sin(soarT * 2 + index) * 2.5 + soarT * 1.2;
          const finalY = exitY + soarT * 2.2;
          const finalZ = exitZ - soarT * 3.5;

          bird.meshGroup.position.set(finalX, finalY, finalZ);
          bird.meshGroup.rotation.set(-0.2, -0.8 + Math.sin(soarT * 3) * 0.2, -0.3);

          if (soarT > 2.0) {
            bird.state = "escaped";
            bird.meshGroup.scale.set(0, 0, 0); // Disappear once far away
          }
        } else if (bird.state === "crash_escaping") {
          // Crash panic escape: Frantic flapping and radial takeoff away from falling cage
          const flightTime = elapsed - bird.escapeStartTime;
          const flapSpeed = 38; // Rapid panic flapping
          bird.leftWing.rotation.z = Math.sin(flightTime * flapSpeed) * 0.85;
          bird.rightWing.rotation.z = -Math.sin(flightTime * flapSpeed) * 0.85;

          const vel = bird.crashVel || new THREE.Vector3(2, 3.5, 2);
          const posX = bird.homePos.x + vel.x * flightTime;
          const posY = bird.homePos.y + vel.y * flightTime - 0.7 * Math.pow(flightTime, 2);
          const posZ = bird.homePos.z + vel.z * flightTime;

          bird.meshGroup.position.set(posX, posY, posZ);

          const heading = Math.atan2(vel.x, vel.z);
          bird.meshGroup.rotation.set(-0.3, heading, Math.sin(flightTime * 10) * 0.35);

          if (flightTime > 2.2) {
            bird.state = "escaped";
            bird.meshGroup.scale.set(0, 0, 0);
          }
        }
      });

      camera.position.z = 6.8;
      camera.lookAt(0, 1.1, 0);

      renderer.render(scene, camera);
    };

    animate();

    // Cleanup
    return () => {
      cancelAnimationFrame(animId);
      window.removeEventListener("resize", handleResize);
      if (renderer.domElement && container.contains(renderer.domElement)) {
        container.removeChild(renderer.domElement);
      }
      renderer.dispose();
    };
  }, []);

  return (
    <div
      ref={mountRef}
      style={{
        position: "absolute",
        inset: 0,
        width: "100%",
        height: "100%",
        pointerEvents: "none",
        zIndex: 2,
      }}
    />
  );
}
