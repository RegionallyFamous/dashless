from pathlib import Path
p=Path(__file__).parent
shapes={
'clipping':('M28 28 H228 V151 L207 158 L197 179 L183 181 L171 195 L155 198 L139 221 H28 Z','',[(50,59,85,25),(50,101,153,25),(50,144,106,25)]),
'rip':('M37 34 H216 L204 83 L224 112 L182 163 L196 192 L175 214 L163 201 H37 Z','rotate(-8 128 128)',[(60,65,72,25),(60,106,135,25),(60,147,91,25)]),
'flyer':('M23 53 L230 35 L224 72 L228 180 L208 180 L189 201 L171 182 L147 193 L122 210 L102 183 L80 193 L62 181 L23 190 Z','',[(48,77,73,22),(45,116,159,24),(59,154,92,22)])
}
for name,(outer,transform,bars) in shapes.items():
 holes=' '.join(f'M{x} {y} h{w} v{h} h{-w} Z' for x,y,w,h in bars)
 (p/(name+'.svg')).write_text(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" role="img" aria-label="Dashless Post Punk {name} vector sketch"><g transform="{transform}"><path fill="#24212b" fill-rule="evenodd" d="{outer} {holes}"/></g></svg>')
