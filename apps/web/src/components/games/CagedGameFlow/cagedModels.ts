import * as THREE from "three";

/** Shared geometry keeps the flock light enough for phone GPUs. */
export function createCagedModels() {
  const sphere = new THREE.SphereGeometry(1, 16, 12);
  const brass = new THREE.MeshStandardMaterial({ color: 0xd8ac59, metalness: .72, roughness: .28 });
  const bronze = new THREE.MeshStandardMaterial({ color: 0x654425, metalness: .65, roughness: .4 });
  const wood = new THREE.MeshStandardMaterial({ color: 0x705038, roughness: .95 });
  const eye = new THREE.MeshStandardMaterial({ color: 0x08151a, roughness: .18 });
  const ivory = new THREE.MeshStandardMaterial({ color: 0xfff4ce, roughness: .65 });
  const beakMaterial = new THREE.MeshStandardMaterial({ color: 0xe5a451, roughness: .5 });
  const colors = [
    [0xe4b749, 0xffe7a1, 0xa76b28],
    [0x399d93, 0xb4e2bf, 0x19596b],
    [0x5696cc, 0xc4e2ec, 0x2a527b],
    [0xc56952, 0xf3c19d, 0x7d3d42],
    [0x9181bd, 0xe2cbed, 0x514875],
  ];
  const palettes = colors.map(([body, breast, wing]) => ({
    body: new THREE.MeshStandardMaterial({ color: body, roughness: .7 }),
    breast: new THREE.MeshStandardMaterial({ color: breast, roughness: .8 }),
    wing: new THREE.MeshStandardMaterial({ color: wing, roughness: .75 }),
  }));
  const ellipsoid = (parent: THREE.Group, material: THREE.Material, position: number[], scale: number[]) => {
    const mesh = new THREE.Mesh(sphere, material);
    mesh.position.set(position[0], position[1], position[2]);
    mesh.scale.set(scale[0], scale[1], scale[2]);
    parent.add(mesh);
    return mesh;
  };
  const rod = (parent: THREE.Group, from: THREE.Vector3, to: THREE.Vector3, radius: number, material: THREE.Material) => {
    const mesh = new THREE.Mesh(new THREE.CylinderGeometry(radius, radius, from.distanceTo(to), 8), material);
    mesh.position.copy(from).add(to).multiplyScalar(.5);
    mesh.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), to.clone().sub(from).normalize());
    parent.add(mesh);
  };

  const cage = new THREE.Group();
  const ring = (radius: number, y: number, thickness: number, material = brass) => {
    const mesh = new THREE.Mesh(new THREE.TorusGeometry(radius, thickness, 8, 64), material);
    mesh.rotation.x = Math.PI / 2;
    mesh.position.y = y;
    cage.add(mesh);
  };
  const base = new THREE.Mesh(new THREE.CylinderGeometry(1.62, 1.68, .16, 64), bronze);
  base.position.y = -.4;
  cage.add(base);
  const floor = new THREE.Mesh(new THREE.CylinderGeometry(1.57, 1.57, .025, 64), wood);
  floor.position.y = -.305;
  cage.add(floor);
  ring(1.64, -.33, .055);
  ring(1.66, -.46, .04);
  ring(1.6, 1.86, .045);
  ring(1.6, .4, .02);
  // Continuous curved ribs replace the triangular wireframe roof.
  for (let i = 0; i < 20; i++) {
    const angle = i / 20 * Math.PI * 2;
    const front = Math.sin(angle) > .8;
    if (front && Math.abs(Math.cos(angle)) < .4) continue;
    const points = [new THREE.Vector3(Math.cos(angle) * 1.6, -.32, Math.sin(angle) * 1.6),
      new THREE.Vector3(Math.cos(angle) * 1.6, 1.86, Math.sin(angle) * 1.6)];
    for (let j = 1; j <= 16; j++) {
      const t = j / 16 * Math.PI / 2;
      points.push(new THREE.Vector3(Math.cos(angle) * 1.6 * Math.cos(t), 1.86 + 1.22 * Math.sin(t), Math.sin(angle) * 1.6 * Math.cos(t)));
    }
    cage.add(new THREE.Mesh(new THREE.TubeGeometry(new THREE.CatmullRomCurve3(points), 36, .014, 6, false), brass));
  }
  ring(.25, 3.08, .04);
  ellipsoid(cage, brass, [0, 3.14, 0], [.16, .12, .16]);
  const hook = new THREE.Mesh(new THREE.TorusGeometry(.19, .035, 8, 32), brass);
  hook.position.y = 3.43;
  cage.add(hook);
  rod(cage, new THREE.Vector3(0, 3.62, 0), new THREE.Vector3(0, 5, 0), .018, bronze);

  const spots: {x: number; y: number; z: number; rotY: number}[] = [];
  for (let tier = 0; tier < 3; tier++) {
    const y = .06 + tier * .73;
    const z = tier === 1 ? .38 : -.36;
    rod(cage, new THREE.Vector3(-1.35, y - .2, z), new THREE.Vector3(1.35, y - .2, z), .035, wood);
    for (let j = 0; j < 5; j++) spots.push({x: (j - 2) * .49, y, z, rotY: (j % 2 ? -.65 : .65)});
  }

  // A complete hinged gate with its own frame and latch.
  const door = new THREE.Group();
  door.position.set(-.54, -.2, 1.52);
  for (const x of [0, .27, .54, .81, 1.08]) rod(door, new THREE.Vector3(x, 0, 0), new THREE.Vector3(x, 1.55, 0), .018, brass);
  for (const y of [0, 1.55]) rod(door, new THREE.Vector3(0, y, 0), new THREE.Vector3(1.08, y, 0), .03, brass);
  ellipsoid(door, brass, [.94, .72, .03], [.055, .055, .045]);
  cage.add(door);

  const bird = (index: number) => {
    const palette = palettes[index % palettes.length];
    const group = new THREE.Group();
    ellipsoid(group, palette.body, [0, 0, 0], [.135, .17, .24]);
    ellipsoid(group, palette.breast, [0, -.025, .115], [.108, .13, .135]);
    ellipsoid(group, palette.body, [0, .17, .145], [.112, .115, .12]);
    for (const side of [-1, 1]) {
      ellipsoid(group, ivory, [side * .092, .185, .196], [.028, .034, .028]);
      ellipsoid(group, eye, [side * .108, .187, .209], [.019, .024, .02]);
      ellipsoid(group, ivory, [side * .113, .195, .22], [.006, .008, .005]);
      rod(group, new THREE.Vector3(side * .048, -.12, .035), new THREE.Vector3(side * .048, -.205, .06), .012, beakMaterial);
      rod(group, new THREE.Vector3(side * .048, -.2, .015), new THREE.Vector3(side * .048, -.2, .115), .009, beakMaterial);
    }
    const beak = new THREE.Mesh(new THREE.ConeGeometry(.035, .095, 8), beakMaterial);
    beak.rotation.x = Math.PI / 2;
    beak.position.set(0, .155, .285);
    group.add(beak);
    for (let i = -1; i <= 1; i++) {
      const tail = ellipsoid(group, palette.wing, [i * .035, -.04, -.275], [.032, .025, .15]);
      tail.rotation.y = -i * .12;
    }
    const wing = (side: number) => {
      const pivot = new THREE.Group();
      pivot.position.set(side * .105, .055, -.035);
      const shoulder = ellipsoid(pivot, palette.body, [side * .1, 0, -.045], [.14, .04, .135]);
      shoulder.rotation.y = side * .2;
      for (let i = 0; i < 4; i++) {
        const feather = ellipsoid(pivot, palette.wing, [side * (.14 + i * .042), -.015, -.09 - i * .025], [.09, .018, .14 - i * .012]);
        feather.rotation.y = side * (.15 + i * .12);
      }
      group.add(pivot);
      return pivot;
    };
    return {group, leftWing: wing(1), rightWing: wing(-1)};
  };
  return {cage, door, spots, bird};
}
