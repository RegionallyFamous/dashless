from pathlib import Path
p=Path(__file__).parent
outer='M 48,81 C 23,74 20,46 37,32 C 53,18 76,27 83,46 C 111,36 145,36 173,46 C 180,27 203,18 219,32 C 236,46 233,74 208,81 C 224,104 230,124 230,145 L 230,184 Q 230,189 226,193 L 187,227 Q 182,232 174,232 L 52,232 Q 26,232 26,207 L 26,145 C 26,124 32,104 48,81 Z'
page='M 53,139 Q 43,139 43,149 L 43,204 Q 43,215 54,215 L 164,215 L 164,187 Q 164,176 175,176 L 213,176 L 213,149 Q 213,139 203,139 Z'
eyes='M 94,95 a 8,8 0 1,0 -16,0 a 8,8 0 1,0 16,0 Z M 178,95 a 8,8 0 1,0 -16,0 a 8,8 0 1,0 16,0 Z'
muzzle='M 158,117 a 30,17 0 1,0 -60,0 a 30,17 0 1,0 60,0 Z'
nose='M 141,113 a 13,7 0 1,0 -26,0 a 13,7 0 1,0 26,0 Z'
line='M 77,162 H 176 a 7,7 0 0,1 0,14 H 77 a 7,7 0 0,1 0,-14 Z'
for name,color,small in [('mark-ink','#24212b',False),('mark-paper','#f7f2e8',False),('mark-black','#000000',False),('mark-small','#24212b',True)]:
 holes=eyes+page+(muzzle if not small else '')
 detail=(nose+line if not small else '')
 svg=f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" role="img" aria-labelledby="title"><title id="title">Dashless bear and page mark</title><path fill="{color}" fill-rule="evenodd" d="{outer} {holes}"/><path fill="{color}" d="{detail}"/></svg>'''
 (p/(name+'.svg')).write_text(svg)
