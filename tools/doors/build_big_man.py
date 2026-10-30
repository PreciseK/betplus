"""Blender-authored stylised Big Man host, celebration clip and portrait fallback."""
import bpy, math
from pathlib import Path
from mathutils import Vector

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT/'apps/web/public/assets/doors'
bpy.ops.object.select_all(action='SELECT')
bpy.ops.object.delete(use_global=False)

def mat(name, color, metal=0, rough=.4):
    m=bpy.data.materials.new(name); m.diffuse_color=(*color,1); m.use_nodes=True
    p=m.node_tree.nodes.get('Principled BSDF')
    p.inputs['Base Color'].default_value=(*color,1)
    p.inputs['Metallic'].default_value=metal; p.inputs['Roughness'].default_value=rough
    return m
skin=mat('Warm skin',(.55,.29,.15),0,.48)
skin_light=mat('Lips and cheeks',(.47,.17,.10),0,.5)
black=mat('Midnight tailored suit',(.012,.02,.024),.1,.4)
gold=mat('Gold embroidery',(.8,.48,.12),.78,.24)
shirt=mat('Ivory open collar',(.86,.82,.67),0,.5)
hair=mat('Dark sculpted hair',(.035,.02,.014),0,.5)
lenses=mat('Smoked sunglasses',(.008,.025,.03),.65,.12)
teeth=mat('Smile',(.95,.88,.68),0,.3)
shoe=mat('Polished loafers',(.012,.012,.011),.3,.19)

root=bpy.data.objects.new('BigMan',None); bpy.context.collection.objects.link(root)
def group(name,loc,parent=root):
    o=bpy.data.objects.new(name,None); bpy.context.collection.objects.link(o);o.parent=parent;o.location=loc;return o
def sphere(name,loc,scale,material,parent=root):
    bpy.ops.mesh.primitive_uv_sphere_add(segments=24,ring_count=16)
    o=bpy.context.object;o.name=name;o.parent=parent;o.location=loc;o.scale=scale;o.data.materials.append(material)
    for p in o.data.polygons:p.use_smooth=True
    return o
def box(name,loc,scale,material,parent=root):
    bpy.ops.mesh.primitive_cube_add(size=1)
    o=bpy.context.object;o.name=name;o.parent=parent;o.location=loc;o.dimensions=scale
    bpy.ops.object.transform_apply(location=False,rotation=False,scale=True)
    o.data.materials.append(material)
    b=o.modifiers.new('Tailored edges','BEVEL');b.width=.025;b.segments=3
    o.modifiers.new('Weighted normals','WEIGHTED_NORMAL');return o
def tube(name,points,radius,material,parent=root):
    c=bpy.data.curves.new(name,'CURVE');c.dimensions='3D';c.resolution_u=12;c.bevel_depth=radius;c.bevel_resolution=3
    s=c.splines.new('POLY');s.points.add(len(points)-1)
    for p,co in zip(s.points,points):p.co=(*co,1)
    o=bpy.data.objects.new(name,c);bpy.context.collection.objects.link(o);o.parent=parent;o.data.materials.append(material)
    bpy.context.view_layer.objects.active=o;o.select_set(True)
    bpy.ops.object.convert(target='MESH');o.select_set(False);return o
def limb(name,start,end,r1,r2,material,parent=root):
    a,b=Vector(start),Vector(end);mid=(a+b)/2
    bpy.ops.mesh.primitive_cone_add(vertices=24,radius1=r1,radius2=r2,depth=(a-b).length)
    o=bpy.context.object;o.name=name;o.parent=parent;o.location=mid;o.rotation_euler=(b-a).to_track_quat('Z','Y').to_euler();o.data.materials.append(material)
    for p in o.data.polygons:p.use_smooth=True
    return o

# Rounded, deliberately stylised silhouette in the cinematic's black/gold wardrobe.
for x in [-.24,.24]:
    limb('Tailored trouser',(x,0,.26),(x,0,1.22),.19,.23,black)
    sphere('Loafer',(x,-.14,.15),(.21,.34,.15),shoe)
    box('Loafer gold buckle',(x,-.36,.22),(.21,.03,.055),gold)
sphere('Jacket body',(0,0,1.65),(.68,.36,.7),black)
sphere('Shirt front',(0,-.31,1.88),(.27,.075,.38),shirt)
for sign in [-1,1]:
    lapel=box('Silk lapel',(sign*.24,-.36,1.98),(.17,.05,.57),black);lapel.rotation_euler.y=sign*.38
    tube('Gold lapel piping',[(sign*.36,-.39,2.26),(sign*.16,-.405,2.0),(sign*.24,-.395,1.74)],.012,gold)
for z in [1.35,1.55,1.75]: sphere('Jacket button',(.1,-.36,z),(.035,.025,.035),gold)
# Embroidered scrolls follow the jacket's front surface.
for sign in [-1,1]:
    for row in range(5):
        for col in range(2):
            cx=sign*(.34+col*.17);cz=1.27+row*.19
            pts=[]
            for k in range(22):
                a=k/21*math.pi*2.1;r=.06*(.3+.7*k/21)
                x=cx+math.cos(a)*r;z=cz+math.sin(a)*r
                y=-.36*math.sqrt(max(.08,1-(x/.72)**2-((z-1.65)/.85)**2))-.016
                pts.append((x,y,z))
            tube('Brocade scroll',pts,.008,gold)
# Neck, oversized head and friendly facial features.
sphere('Neck',(0,0,2.25),(.23,.22,.25),skin)
sphere('Head',(0,-.005,2.63),(.37,.3,.43),skin)
for sign in [-1,1]:
    sphere('Ear',(sign*.36,0,2.65),(.07,.08,.12),skin)
    sphere('Cheek',(sign*.2,-.22,2.54),(.17,.13,.15),skin)
sphere('Chin',(0,-.13,2.37),(.25,.2,.15),skin)
sphere('Nose',(0,-.32,2.63),(.09,.12,.12),skin)
sphere('Nose tip',(0,-.4,2.59),(.1,.07,.055),skin)
sphere('Hair cap',(0,.015,2.94),(.35,.28,.16),hair)
for i in range(8):
    sphere('Swept hair lock',(-.24+i*.067,-.08,2.99+math.sin(i*.4)*.03),(.11,.22,.07),hair)
for sign in [-1,1]:
    lens=box('Sunglass lens',(sign*.17,-.305,2.76),(.28,.06,.17),lenses);lens.rotation_euler.y=sign*.1
    tube('Sunglass gold rim',[(sign*.3,-.342,2.83),(sign*.04,-.342,2.83),(sign*.04,-.345,2.68),(sign*.28,-.342,2.68),(sign*.3,-.342,2.83)],.009,gold)
    tube('Glasses arm',[(sign*.3,-.31,2.8),(sign*.37,-.04,2.78)],.013,gold)
tube('Glasses bridge',[(-.04,-.35,2.79),(0,-.37,2.8),(.04,-.35,2.79)],.013,gold)
smile=[((i/16-.5)*.34,-.304- .018*math.sin(i/16*math.pi),2.48-.042*math.sin(i/16*math.pi)) for i in range(17)]
tube('Smile lip',smile,.029,skin_light)
tube('Smile teeth',[(x,y-.025,z+.013) for x,y,z in smile],.013,teeth)
# A curved gold chain and medallion.
tube('Gold necklace',[((i/32-.5)*.4,-.37-.035*math.sin(i/32*math.pi),2.24-.22*math.sin(i/32*math.pi)) for i in range(33)],.018,gold)
sphere('Medallion',(0,-.425,1.99),(.078,.022,.095),gold)
box('Medallion mark',(0,-.45,1.99),(.025,.009,.07),shirt)

# Presenter arm on the viewer's right; other hand rests beside the hip.
presenter=group('PresenterArm',(.57,0,2.05))
limb('Upper sleeve',(0,0,0),(.25,-.02,-.3),.19,.16,black,presenter)
limb('Fore sleeve',(.25,-.02,-.3),(.57,-.1,-.1),.15,.11,black,presenter)
limb('Ivory cuff',(.54,-.1,-.11),(.64,-.11,-.075),.115,.11,shirt,presenter)
sphere('Open palm',(.73,-.11,-.05),(.14,.065,.085),skin,presenter)
for i in range(4):
    start=(.73+i*.024,-.12,-.005+i*.016)
    end=(.83+i*.026,-.12,.04+i*.035)
    limb('Presenter finger',start,end,.024,.018,skin,presenter)
    sphere('Fingertip',end,(.018,.022,.02),skin,presenter)
limb('Thumb',(.67,-.11,-.03),(.69,-.13,.10),.035,.025,skin,presenter)
other=group('RestingArm',(-.58,0,2.03))
limb('Resting upper sleeve',(0,0,0),(-.2,0,-.37),.18,.15,black,other)
limb('Resting fore sleeve',(-.2,0,-.37),(-.05,-.28,-.53),.15,.105,black,other)
sphere('Resting hand',(-.04,-.3,-.6),(.11,.07,.14),skin,other)
box('Gold wristwatch',(-.065,-.31,-.47),(.16,.07,.1),gold,other)
for f,angle in [(1,-.12),(14,.12),(28,-.04),(42,.05),(56,0)]:
    presenter.rotation_euler.y=angle;presenter.keyframe_insert(data_path='rotation_euler',frame=f)
bpy.context.scene.render.fps=30;bpy.context.scene.frame_end=56;bpy.context.scene.frame_set(1)

bpy.ops.object.select_all(action='DESELECT');root.select_set(True)
for o in root.children_recursive:o.select_set(True)
bpy.context.view_layer.objects.active=root
bpy.ops.export_scene.gltf(filepath=str(OUT/'models/big-man.glb'),export_format='GLB',use_selection=True,export_apply=True,export_animations=True,export_frame_range=True)

# Transparent rendered portrait is also used when WebGL is unavailable.
scene=bpy.context.scene;scene.render.engine='CYCLES';scene.cycles.samples=24
scene.render.resolution_x=640;scene.render.resolution_y=800;scene.render.resolution_percentage=100
scene.render.film_transparent=True;scene.world.color=(.16,.16,.16)
bpy.ops.object.camera_add(location=(3,-8,3.1));camera=bpy.context.object
camera.rotation_euler=(Vector((.12,0,1.58))-camera.location).to_track_quat('-Z','Y').to_euler()
camera.data.type='ORTHO';camera.data.ortho_scale=3.65;scene.camera=camera
for name,loc,power,color,size in [('Key',(-3,-4,6),650,(1,.85,.65),4),('Fill',(4,-3,4),500,(.5,.85,1),3),('Rim',(1,2,5),800,(1,.65,.25),3)]:
    bpy.ops.object.light_add(type='AREA',location=loc);light=bpy.context.object;light.name=name;light.data.energy=power;light.data.color=color;light.data.shape='DISK';light.data.size=size
    light.rotation_euler=(Vector((0,0,1.6))-light.location).to_track_quat('-Z','Y').to_euler()
scene.render.image_settings.file_format='PNG';scene.render.filepath=str(OUT/'big-man-portrait.png')
bpy.ops.wm.save_as_mainfile(filepath=str(ROOT/'tools/doors/big-man.blend'))
bpy.ops.render.render(write_still=True)
print('BIG_MAN_COMPLETE')
