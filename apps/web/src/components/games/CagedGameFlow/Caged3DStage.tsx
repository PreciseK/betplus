"use client";

import React, { useEffect, useRef } from "react";
import * as THREE from "three";
import { createCagedModels } from "./cagedModels";

export interface Caged3DStageProps {
  roundPhase: "betting" | "flying" | "crashed";
  multiplier: number;
}

export type BirdEscape3DStageProps = Caged3DStageProps;

interface BirdInstance {
  meshGroup: THREE.Group;
  leftWing: THREE.Group;
  rightWing: THREE.Group;
  homePos: THREE.Vector3;
  homeRot: THREE.Euler;
  state: "in_cage" | "escaping" | "crash_escaping" | "escaped";
  escapeStartTime: number;
  perchSeed: number;
  crashVel?: THREE.Vector3;
}

export function Caged3DStage({ roundPhase, multiplier }: Caged3DStageProps) {
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
    // whole game; the betting controls remain usable without the decorative scene.
    let renderer: THREE.WebGLRenderer;
    try {
      renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    } catch (error) {
      console.warn("Caged3DStage: WebGL unavailable, 3D stage disabled.", error);
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
    let cameraDistance = 8.2;
    const cameraTarget = new THREE.Vector3(0, 1.55, 0);
    camera.position.set(0, 2.1, cameraDistance);
    camera.lookAt(cameraTarget);
    cameraRef.current = camera;

    // 3. Renderer
    renderer.setSize(container.clientWidth, container.clientHeight);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, container.clientWidth < 600 ? 1.5 : 2));
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.25;
    rendererRef.current = renderer;
    container.appendChild(renderer.domElement);

    // 4. Lights
    const ambientLight = new THREE.HemisphereLight(0xe8f5ff, 0x263e2c, 2.6);
    scene.add(ambientLight);

    const moonLight = new THREE.DirectionalLight(0xffe7ba, 3.5);
    moonLight.position.set(-3, 6, 5);
    const rim = new THREE.DirectionalLight(0x9bcdd4, 2);
    rim.position.set(3, 3, -3);
    scene.add(rim);
    scene.add(moonLight);

    const goldPointLight = new THREE.PointLight(0xffdb8c, 1.8, 8);
    goldPointLight.position.set(0, 1.2, 0);
    scene.add(goldPointLight);
    goldPointLightRef.current = goldPointLight;

    const models = createCagedModels();
    const cageGroup = models.cage;
    cageGroupRef.current = cageGroup;
    cageDoorRef.current = models.door;
    scene.add(cageGroup);

    // Three clear perching rows; all birds are decorative, independent of odds.
    const totalBirds = models.spots.length;
    const birdsList: BirdInstance[] = [];
    for (let i = 0; i < totalBirds; i++) {
      const spot = models.spots[i];
      const {group: birdGroup, leftWing, rightWing} = models.bird(i);
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
    const pCount = 45;
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
      size: 0.025,
      transparent: true,
      opacity: 0.35,
      blending: THREE.AdditiveBlending,
    });
    const particles = new THREE.Points(pGeo, pMat);
    particlesRef.current = particles;
    scene.add(particles);

    // 8. Resize Listener
    const handleResize = () => {
      if (!container || !camera || !renderer) return;
      camera.aspect = container.clientWidth / Math.max(container.clientHeight, 1);
      // Fit the entire cage, including the hook, inside narrow phone stages.
      cameraDistance = Math.max(container.clientWidth < 600 ? 9.2 : 8.5, 4.7 / camera.aspect);
      camera.position.z = cameraDistance;
      camera.setViewOffset(container.clientWidth, container.clientHeight, 0, container.clientHeight * .12, container.clientWidth, container.clientHeight);
      camera.updateProjectionMatrix();
      renderer.setSize(container.clientWidth, container.clientHeight);
    };
    const observer = new ResizeObserver(handleResize);
    observer.observe(container);
    handleResize();
    const motionQuery = window.matchMedia("(prefers-reduced-motion: reduce)");
    let reduceMotion = motionQuery.matches;
    const onMotionChange = () => { reduceMotion = motionQuery.matches; };
    motionQuery.addEventListener("change", onMotionChange);

    // 9. Animation Loop
    const startEpoch = performance.now();
    let previousFrame = startEpoch;
    let animId: number;

    const animate = () => {
      animId = requestAnimationFrame(animate);
      const now = performance.now();
      const elapsed = (now - startEpoch) * 0.001;
      const delta = Math.min((now - previousFrame) * .001, .05);
      previousFrame = now;
      if (document.hidden) return;

      const phase = phaseRef.current;
      const mult = multiplierRef.current;

      // Animate ambient fireflies
      if (!reduceMotion && particlesRef.current && particlePositionsRef.current) {
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
          cageDoorRef.current.rotation.y = 0;
        }
        birdsRef.current.forEach((b) => {
          b.state = "in_cage";
          b.meshGroup.position.copy(b.homePos);
          b.meshGroup.rotation.copy(b.homeRot);
          b.meshGroup.scale.set(1, 1, 1);
          b.leftWing.rotation.set(0, 0, -1.1);
          b.rightWing.rotation.set(0, 0, 1.1);
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
          cageGroupRef.current.rotation.y = crashT * .65;

          // Door swings open violently during fall
          if (cageDoorRef.current) {
            cageDoorRef.current.rotation.y = 1.4 + Math.sin(crashT * 14) * 0.4;
          }

          // Screen impact camera shake during the initial severance/drop
          const shake = reduceMotion ? 0 : Math.max(0, 0.07 * (1 - crashT / 0.4));
          camera.position.x = (Math.random() - 0.5) * shake;
          camera.position.y = 2.1 + (Math.random() - 0.5) * shake;
        } else {
          // Normal gentle hover / sway
          cageGroupRef.current.position.y = reduceMotion ? 0 : Math.sin(elapsed * .8) * .018;
          cageGroupRef.current.position.x = 0;
          cageGroupRef.current.rotation.y = reduceMotion ? 0 : Math.sin(elapsed * .5) * .015;
          cageGroupRef.current.rotation.x = 0;
          cageGroupRef.current.rotation.z = 0;
          if (cageDoorRef.current) {
            cageDoorRef.current.rotation.y = THREE.MathUtils.damp(cageDoorRef.current.rotation.y, phase === "flying" ? -1.25 : 0, 6, delta);
          }
          camera.position.x = reduceMotion ? 0 : Math.sin(elapsed * .3) * .04;
          camera.position.y = 2.1;
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
          const hop = reduceMotion ? 0 : Math.sin(elapsed * 2.5 + bird.perchSeed) * .008;
          const headTurn = reduceMotion ? 0 : Math.sin(elapsed * 1.2 + bird.perchSeed) * .12;
          bird.meshGroup.position.set(bird.homePos.x, bird.homePos.y + hop, bird.homePos.z);
          bird.meshGroup.rotation.set(0, bird.homeRot.y + headTurn, 0);

          // Wings rest folded
          bird.leftWing.rotation.z = -1.1 + (reduceMotion ? 0 : Math.sin(elapsed * 2 + bird.perchSeed) * .025);
          bird.rightWing.rotation.z = 1.1 - (reduceMotion ? 0 : Math.sin(elapsed * 2 + bird.perchSeed) * .025);
        } else if (bird.state === "escaping") {
          // Progressive escape during flight: out the door and into the sky
          const flightTime = elapsed - bird.escapeStartTime;
          const flapSpeed = 22;
          bird.leftWing.rotation.z = Math.sin(flightTime * flapSpeed) * 0.7;
          bird.rightWing.rotation.z = -Math.sin(flightTime * flapSpeed) * 0.7;

          // Door opening coordinates: exit towards (1.6, 0.8, 1.2) then soar upward into distance
          const exitT = THREE.MathUtils.smoothstep(flightTime, 0, .7);
          const soarT = Math.max(0, flightTime - 0.7);

          // Phase 1: Fly from home position out through the cage door
          const exitX = THREE.MathUtils.lerp(bird.homePos.x, 0, exitT);
          const exitY = THREE.MathUtils.lerp(bird.homePos.y, .8, exitT);
          const exitZ = THREE.MathUtils.lerp(bird.homePos.z, 1.9, exitT);

          // Phase 2: Soar upward into the sky and fade into distance
          const finalX = exitX + (Math.sin(soarT * 2 + index) - Math.sin(index)) * 1.2 + soarT * 1.2;
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

      if (reduceMotion) {
        cageGroup.visible = phase !== "crashed";
        for (const bird of birdsRef.current) {
          bird.meshGroup.visible = bird.state === "in_cage";
        }
      } else {
        cageGroup.visible = true;
        for (const bird of birdsRef.current) bird.meshGroup.visible = true;
      }
      camera.position.z = cameraDistance;
      camera.lookAt(cameraTarget);

      renderer.render(scene, camera);
    };

    animate();

    // Cleanup
    return () => {
      cancelAnimationFrame(animId);
      observer.disconnect();
      motionQuery.removeEventListener("change", onMotionChange);
      const geometries = new Set<THREE.BufferGeometry>();
      const materials = new Set<THREE.Material>();
      scene.traverse((object) => {
        if (object instanceof THREE.Mesh || object instanceof THREE.Points) {
          geometries.add(object.geometry);
          for (const material of Array.isArray(object.material) ? object.material : [object.material]) materials.add(material);
        }
      });
      geometries.forEach((geometry) => geometry.dispose());
      materials.forEach((material) => material.dispose());
      birdsRef.current = [];
      crashStartTimeRef.current = null;
      lastEscapedCountRef.current = 0;
      if (renderer.domElement && container.contains(renderer.domElement)) {
        container.removeChild(renderer.domElement);
      }
      renderer.dispose();
    };
  }, []);

  return (
    <div
      ref={mountRef}
      aria-hidden="true"
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

export const BirdEscape3DStage = Caged3DStage;
