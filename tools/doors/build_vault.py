"""Run with Blender --background --python tools/doors/build_vault.py.

Creates an editable asset library and four browser-ready GLBs. Dimensions in m.
"""
import bpy
import math
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "apps/web/public/assets/doors/models"
OUT.mkdir(parents=True, exist_ok=True)
bpy.ops.object.select_all(action='SELECT')
bpy.ops.object.delete(use_global=False)

def material(name, color, metal=0, rough=.35, glow=None):
    m = bpy.data.materials.new(name)
    m.use_nodes = True
    p = m.node_tree.nodes.get('Principled BSDF')
    p.inputs['Base Color'].default_value = (*color, 1)
    p.inputs['Metallic'].default_value = metal
    p.inputs['Roughness'].default_value = rough
    if glow:
        p.inputs['Emission Color'].default_value = (*color, 1)
        p.inputs['Emission Strength'].default_value = glow
    return m

steel = material('Midnight brushed steel', (.055, .1, .115), .82, .29)
dark = material('Recess shadow', (.009, .018, .022), .25, .65)
gold = material('Warm champagne brass', (.66, .39, .11), .85, .24)
bright = material('Polished bullion', (.95, .64, .15), .9, .18)
silver = material('Bolt steel', (.35, .43, .46), .85, .22)
teal = material('Cyan indicator', (.04, .75, .85), .3, .25, 3)
emerald = material('Emerald enamel', (.015, .26, .14), .55, .23)
wood = material('Chest dark walnut', (.11, .038, .012), .1, .4)

def empty(name, loc=(0,0,0), parent=None):
    o = bpy.data.objects.new(name, None)
    bpy.context.collection.objects.link(o)
    o.parent = parent
    o.location = loc
    return o

def box(name, loc, size, mat, parent, bevel=.025):
    bpy.ops.mesh.primitive_cube_add(size=1)
    o = bpy.context.object
    o.name = name
    o.dimensions = size
    bpy.ops.object.transform_apply(location=False, rotation=False, scale=True)
    o.parent = parent
    o.location = loc
    o.data.materials.append(mat)
    if bevel:
        mod = o.modifiers.new('Machined bevels', 'BEVEL')
        mod.width = bevel
        mod.segments = 2
        o.modifiers.new('Weighted corner normals', 'WEIGHTED_NORMAL')
    return o

def cylinder(name, loc, radius, depth, mat, parent, axis='Y', vertices=24):
    bpy.ops.mesh.primitive_cylinder_add(vertices=vertices, radius=radius, depth=depth)
    o = bpy.context.object
    o.name = name
    o.parent = parent
    o.location = loc
    if axis == 'Y': o.rotation_euler.x = math.pi/2
    o.data.materials.append(mat)
    bevel = o.modifiers.new('Soft metal edge', 'BEVEL')
    bevel.width = .008
    bevel.segments = 2
    o.modifiers.new('Weighted normals', 'WEIGHTED_NORMAL')
    return o

def export(root, filename):
    bpy.ops.object.select_all(action='DESELECT')
    root.select_set(True)
    for o in root.children_recursive: o.select_set(True)
    bpy.context.view_layer.objects.active = root
    bpy.ops.export_scene.gltf(filepath=str(OUT/filename), export_format='GLB',
        use_selection=True, export_animations=True, export_apply=True,
        export_yup=True, export_frame_range=True)

bpy.context.scene.render.fps = 30
bpy.context.scene.frame_end = 24
vault = empty('Compartment')
box('Back wall', (0,.27,0), (1.8,.1,1.48), dark, vault)
for x in [-.87,.87]: box('Side wall', (x,0,0), (.08,.62,1.5), steel, vault)
for z in [-.73,.73]: box('Shelf', (0,0,z), (1.8,.62,.08), steel, vault)
for x in [-.89,.89]: box('Outer gold rail', (x,-.35,0), (.045,.07,1.53), gold, vault, .012)
for z in [-.75,.75]: box('Outer gold rail', (0,-.35,z), (1.82,.07,.045), gold, vault, .012)
for x in [-.8,.8]:
    for z in [-.64,0,.64]: cylinder('Frame rivet',(x,-.395,z),.026,.025,bright,vault,vertices=12)
box('Interior cyan strip',(0,.18,.64),(1.4,.03,.025),teal,vault,.008)
hinge = empty('DoorPivot',(-.8,-.39,0),vault)
box('Armoured door',(.8,0,0),(1.59,.1,1.32),steel,hinge,.035)
box('Inset front panel',(.8,-.064,0),(1.34,.035,1.07),dark,hinge,.025)
for x in [.12,1.48]: box('Door brass upright',(x,-.09,0),(.045,.045,1.15),gold,hinge,.009)
for z in [-.57,.57]: box('Door brass horizontal',(.8,-.09,z),(1.39,.045,.045),gold,hinge,.009)
for x in [.19,1.41]:
    for z in [-.49,.49]: cylinder('Door rivet',(x,-.12,z),.023,.02,bright,hinge,vertices=12)
for z in [-.42,.42]:
    box('Hinge strap',(.11,-.14,z),(.36,.07,.115),silver,hinge,.015)
    cylinder('Hinge pin',(.015,-.1,z),.05,.23,gold,hinge,axis='Z')
rotor = empty('LockRotor',(.8,-.16,0),hinge)
cylinder('Lock surround',(0,0,0),.225,.065,gold,rotor)
cylinder('Lock dial',(0,-.052,0),.175,.055,silver,rotor)
cylinder('Lock core',(0,-.09,0),.1,.04,gold,rotor)
for a in range(0,360,45):
    r=math.radians(a)
    box('Dial tick',(math.cos(r)*.145,-.09,math.sin(r)*.145),(.014,.015,.035),bright,rotor,.004)
box('Handle bar',(1.23,-.2,0),(.09,.14,.37),gold,hinge,.025)
bolts=[]
for z in [-.38,.38]:
    bolts.append(box('Retracting bolt',(1.56,.025,z),(.25,.08,.09),silver,hinge,.012))
for frame,angle in [(1,0),(7,0),(24,-1.85)]:
    hinge.rotation_euler.z=angle
    hinge.keyframe_insert(data_path='rotation_euler',frame=frame)
for frame,angle in [(1,0),(7,math.pi*.7),(24,math.pi*.7)]:
    rotor.rotation_euler.y=angle
    rotor.keyframe_insert(data_path='rotation_euler',frame=frame)
for bolt in bolts:
    for frame,x in [(1,1.56),(7,1.37),(24,1.37)]:
        bolt.location.x=x
        bolt.keyframe_insert(data_path='location',frame=frame)
bpy.context.scene.frame_set(1)
export(vault,'compartment.glb')

bars=empty('GoldBars')
for row,count in [(0,3),(1,2),(2,1)]:
    for i in range(count):
        box('Bullion bar',((i-(count-1)/2)*.32,0,-.48+row*.17),(.3,.48,.15),bright,bars,.035)
for i in range(8):
    cylinder('Gold coin',((i%4-1.5)*.2,-.3,-.57+(i//4)*.025),.09,.023,gold,bars,axis='Z',vertices=16)
export(bars,'gold-bars.glb')

chest=empty('TreasureChest')
box('Chest body',(0,0,-.34),(1.05,.65,.48),wood,chest,.04)
box('Chest gold lining',(0,0,-.08),(.95,.57,.035),bright,chest)
for x in [-.43,.43]: box('Chest vertical band',(x,-.345,-.34),(.07,.04,.48),gold,chest,.01)
for z in [-.55,-.12]: box('Chest rim',(0,-.345,z),(1.05,.04,.05),gold,chest,.01)
lid=empty('ChestLid',(0,.3,-.08),chest)
box('Lid',(0,-.3,.1),(1.06,.67,.2),wood,lid,.06)
for x in [-.43,.43]: box('Lid band',(x,-.3,.21),(.07,.65,.025),gold,lid,.01)
box('Chest clasp',(0,-.355,-.18),(.14,.045,.18),gold,chest,.015)
for i in range(15):
    cylinder('Chest coin',((i%5-2)*.15,(i//5-1)*.14,-.04+(i%3)*.025),.095,.025,bright,chest,axis='Z',vertices=16)
for frame,angle in [(1,0),(24,-1.18)]:
    lid.rotation_euler.x=angle
    lid.keyframe_insert(data_path='rotation_euler',frame=frame)
bpy.context.scene.frame_set(1)
export(chest,'treasure-chest.glb')

plaque=empty('MultiplierPlaque')
box('Plaque gold body',(0,0,-.05),(.72,.09,1.03),gold,plaque,.045)
box('Plaque emerald face',(0,-.055,-.05),(.63,.035,.94),emerald,plaque,.03)
for z in [-.45,.35]:
    for x in [-.23,.23]:
        jewel=box('Corner ornament',(x,-.08,z),(.1,.025,.1),bright,plaque,.01)
        jewel.rotation_euler.y=math.pi/4
box('Text mounting face',(0,-.08,-.05),(.47,.015,.52),emerald,plaque,.01)
export(plaque,'multiplier-plaque.glb')

# Keep the editable library arranged for inspection in Blender.
vault.location.x=-3
bars.location.x=-.7
chest.location.x=1
plaque.location.x=2.8
bpy.context.scene.frame_set(1)
bpy.ops.wm.save_as_mainfile(filepath=str(ROOT/'tools/doors/vault-assets.blend'))
print('DOORS_ASSETS_COMPLETE',OUT)
