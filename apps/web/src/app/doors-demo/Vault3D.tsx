"use client";

import { useEffect, useRef } from "react";
import type * as THREE from "three";
import s from "./doors.module.css";

export type VaultState = { id: number | string; opened: number[]; prizes: number[] } | null;
type Runtime = { sync: (state: VaultState) => void; dispose: () => void };

export default function Vault3D({ state, onReady, onUnavailable, showcase = false }: {
  state: VaultState; onReady: () => void; onUnavailable: () => void; showcase?: boolean;
}) {
  const mount = useRef<HTMLDivElement>(null);
  const runtime = useRef<Runtime | null>(null);
  const latest = useRef(state);
  useEffect(() => { latest.current = state; }, [state]);

  useEffect(() => {
    let cancelled = false;
    const host = mount.current!;
    let renderer: THREE.WebGLRenderer | null = null;
    let dispose: (() => void) | undefined;
    async function boot() {
      try {
        const [T, { GLTFLoader }, { RoomEnvironment }, { mergeGeometries }] = await Promise.all([
          import("three"), import("three/examples/jsm/loaders/GLTFLoader.js"),
          import("three/examples/jsm/environments/RoomEnvironment.js"),
          import("three/examples/jsm/utils/BufferGeometryUtils.js"),
        ]);
        if (cancelled) return;
        renderer = new T.WebGLRenderer({ alpha: true, antialias: true, powerPreference: "high-performance" });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 1.5));
        renderer.setClearColor(0, 0);
        renderer.toneMapping = T.ACESFilmicToneMapping;
        renderer.toneMappingExposure = .9;
        renderer.domElement.setAttribute("aria-hidden", "true");
        host.appendChild(renderer.domElement);
        const scene = new T.Scene();
        const camera = new T.OrthographicCamera(-5, 5, 4, -4, .1, 50);
        camera.position.z = 16;
        const pmrem = new T.PMREMGenerator(renderer);
        const room = new RoomEnvironment();
        const environment = pmrem.fromScene(room, .04);
        scene.environment = environment.texture;
        scene.environmentIntensity = .35;
        room.dispose();
        pmrem.dispose();
        scene.add(new T.HemisphereLight(0xb4f8ff, 0x3e2310, .3));
        const warm = new T.DirectionalLight(0xffd789, 2.4);
        warm.position.set(-3, 5, 8);
        scene.add(warm);
        const cyan = new T.DirectionalLight(0x52e6ff, .8);
        cyan.position.set(5, 2, 3);
        scene.add(cyan);
        dispose = () => { environment.dispose(); renderer!.dispose(); renderer!.domElement.remove(); };
        const loader = new GLTFLoader();
        const assets = await Promise.all(["compartment", "gold-bars", "treasure-chest", "multiplier-plaque"].map(name => loader.loadAsync(`/assets/doors/models/${name}.glb`)));
        const geometries = new Set<THREE.BufferGeometry>();
        const materials = new Set<THREE.Material>();
        const textures = new Set<THREE.Texture>();
        assets.forEach(asset => asset.scene.traverse(node => {
          if (node instanceof T.Mesh) {
            geometries.add(node.geometry);
            (Array.isArray(node.material) ? node.material : [node.material]).forEach(m => materials.add(m));
          }
        }));
        if (cancelled) {
          geometries.forEach(geometry => geometry.dispose());
          materials.forEach(material => material.dispose());
          return;
        }
        // Merge rigid parts by material while preserving the Blender animation targets.
        const animated = (name: string) => /DoorPivot|LockRotor|Retracting|ChestLid/.test(name);
        function batch(root: THREE.Object3D) {
          root.updateWorldMatrix(true, true);
          const inverse = root.matrixWorld.clone().invert();
          const batches = new Map<THREE.Material, THREE.Mesh[]>();
          function collect(node: THREE.Object3D) {
            for (const child of [...node.children]) {
              if (animated(child.name)) { batch(child); continue; }
              if (child instanceof T.Mesh && !Array.isArray(child.material)) {
                const group = batches.get(child.material) ?? [];
                group.push(child);
                batches.set(child.material, group);
              } else collect(child);
            }
          }
          collect(root);
          batches.forEach((meshes, material) => {
            if (meshes.length < 2) return;
            const pieces = meshes.map(mesh => {
              const geometry = mesh.geometry.index ? mesh.geometry.toNonIndexed() : mesh.geometry.clone();
              geometry.applyMatrix4(new T.Matrix4().multiplyMatrices(inverse, mesh.matrixWorld));
              return geometry;
            });
            const merged = mergeGeometries(pieces);
            pieces.forEach(geometry => geometry.dispose());
            if (!merged) return;
            geometries.add(merged);
            const mesh = new T.Mesh(merged, material);
            mesh.name = "BatchedRigidParts";
            meshes.forEach(original => original.removeFromParent());
            root.add(mesh);
          });
        }
        assets.forEach(asset => batch(asset.scene));
        const cells: { group: THREE.Group; model: THREE.Object3D; mixer: THREE.AnimationMixer; treasure?: THREE.Object3D; treasureMixer?: THREE.AnimationMixer; opened: boolean; age: number; }[] = [];
        const columns = showcase ? 3 : 5;
        const rows = showcase ? 1 : 4;
        for (let i = 0; i < columns * rows; i++) {
          const group = new T.Group();
          const model = assets[0].scene.clone(true);
          group.add(model);
          scene.add(group);
          const mixer = new T.AnimationMixer(model);
          cells.push({ group, model, mixer, opened: false, age: 0 });
        }
        const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        let frame = 0;
        let last = performance.now();
        let activeUntil = 0;
        let roundId: number | string | undefined;
        let dead = false;
        function paint(now: number) {
          if (dead) return;
          const delta = Math.max((now - last) / 1000, 0);
          last = now;
          cells.forEach(cell => {
            cell.mixer.update(delta);
            cell.treasureMixer?.update(delta);
            if (cell.opened && cell.treasure) {
              cell.age += delta;
              const progress = reduced ? 1 : Math.min(cell.age / .8, 1);
              cell.treasure.scale.setScalar(.88 * (1 - Math.pow(1 - progress, 3)));
              cell.treasure.rotation.y = reduced ? -.12 : Math.sin(cell.age * 1.2) * .12;
            }
          });
          renderer!.render(scene, camera);
          host.dataset.drawCalls = String(renderer!.info.render.calls);
          host.dataset.triangles = String(renderer!.info.render.triangles);
          host.dataset.frames = String(Number(host.dataset.frames ?? 0) + 1);
          frame = now < activeUntil ? requestAnimationFrame(paint) : 0;
        }
        function invalidate(seconds = .1) {
          activeUntil = performance.now() + seconds * 1000;
          if (!frame) { last = performance.now(); frame = requestAnimationFrame(paint); }
        }
        function resize() {
          const { width, height } = host.getBoundingClientRect();
          if (!width || !height) return;
          renderer!.setSize(width, height, false);
          const h = 10 * height / width;
          camera.top = h / 2;
          camera.bottom = -h / 2;
          camera.updateProjectionMatrix();
          cells.forEach((cell, i) => {
            cell.group.position.set((i % columns - (columns - 1) / 2) * (10 / columns), ((rows - 1) / 2 - Math.floor(i / columns)) * h / rows, 0);
            cell.group.scale.set((10 / columns - .04) / 1.82, (h / rows - .045) / 1.53, .85);
          });
          invalidate();
        }
        function play(mixer: THREE.AnimationMixer, clips: THREE.AnimationClip[]) {
          clips.forEach(clip => {
            const action = mixer.clipAction(clip);
            action.reset().setLoop(T.LoopOnce, 1);
            action.clampWhenFinished = true;
            action.play();
          });
          if (reduced) mixer.update(2);
        }
        function sync(value: VaultState) {
          if (roundId !== value?.id) {
            roundId = value?.id;
            cells.forEach(cell => {
              cell.mixer.stopAllAction();
              cell.mixer.setTime(0);
              cell.treasureMixer?.stopAllAction();
              cell.treasure?.traverse(node => {
                if (node instanceof T.Sprite) {
                  if (node.material.map) { node.material.map.dispose(); textures.delete(node.material.map); }
                  node.material.dispose();
                  materials.delete(node.material);
                }
              });
              cell.treasure?.removeFromParent();
              cell.treasure = undefined;
              cell.treasureMixer = undefined;
              cell.opened = false;
              cell.age = 0;
            });
          }
          value?.opened.forEach(i => {
            const cell = cells[i];
            if (!cell || cell.opened) return;
            cell.opened = true;
            play(cell.mixer, assets[0].animations);
            const prize = value.prizes[i];
            if (prize > 0) {
              const asset = assets[prize === 5 ? 2 : prize === 2 ? 3 : 1];
              cell.treasure = asset.scene.clone(true);
              cell.treasure.position.set(0, .1, .06);
              cell.model.add(cell.treasure);
              if (prize === 2) {
                const canvas = document.createElement("canvas");
                canvas.width = 256;
                canvas.height = 160;
                const context = canvas.getContext("2d")!;
                context.font = "bold 100px Georgia";
                context.textAlign = "center";
                context.textBaseline = "middle";
                context.fillStyle = "#ffe295";
                context.shadowColor = "#35220a";
                context.shadowBlur = 4;
                context.fillText(`×${prize}`, 128, 80);
                const texture = new T.CanvasTexture(canvas);
                texture.colorSpace = T.SRGBColorSpace;
                textures.add(texture);
                const material = new T.SpriteMaterial({ map: texture, depthTest: true });
                materials.add(material);
                const text = new T.Sprite(material);
                text.position.set(0, -.05, .16);
                text.scale.set(.49, .31, 1);
                cell.treasure.add(text);
              }
              if (asset.animations.length) {
                cell.treasureMixer = new T.AnimationMixer(cell.treasure);
                play(cell.treasureMixer, asset.animations);
              }
            }
          });
          invalidate(reduced ? .1 : 2.2);
        }
        const observer = new ResizeObserver(resize);
        observer.observe(host);
        const lost = (event: Event) => { event.preventDefault(); onUnavailable(); };
        renderer.domElement.addEventListener("webglcontextlost", lost);
        dispose = () => {
          dead = true;
          cancelAnimationFrame(frame);
          observer.disconnect();
          renderer!.domElement.removeEventListener("webglcontextlost", lost);
          cells.forEach(cell => { cell.mixer.stopAllAction(); cell.treasureMixer?.stopAllAction(); });
          geometries.forEach(geometry => geometry.dispose());
          materials.forEach(material => material.dispose());
          textures.forEach(texture => texture.dispose());
          environment.dispose();
          renderer!.dispose();
          renderer!.domElement.remove();
        };
        if (cancelled) { dispose(); return; }
        runtime.current = { sync, dispose };
        resize();
        await renderer.compileAsync(scene, camera);
        if (cancelled) { dispose(); return; }
        sync(latest.current);
        onReady();
      } catch (error) {
        dispose?.();
        renderer?.dispose();
        renderer?.domElement.remove();
        if (!cancelled) { console.warn("Vault 3D unavailable; using illustrated board.", error); onUnavailable(); }
      }
    }
    void boot();
    return () => { cancelled = true; dispose?.(); runtime.current = null; };
  }, [onReady, onUnavailable, showcase]);

  useEffect(() => { runtime.current?.sync(state); }, [state]);
  return <div ref={mount} className={s.canvas3d} data-vault-renderer="three" />;
}
