from pathlib import Path
p=Path(__file__).parent
outer='M 48,81 C 23,74 20,46 37,32 C 53,18 76,27 83,46 C 111,36 145,36 173,46 C 180,27 203,18 219,32 C 236,46 233,74 208,81 C 224,103 230,122 230,145 L 230,165 Q 230,170 226,174 L 187,209 Q 182,214 174,214 L 52,214 Q 26,214 26,189 L 26,145 C 26,122 32,103 48,81 Z'
eyes='M 95,104 a 9,9 0 1,0 -18,0 a 9,9 0 1,0 18,0 Z M 179,104 a 9,9 0 1,0 -18,0 a 9,9 0 1,0 18,0 Z'
muzzle='M 158,143 a 30,19 0 1,0 -60,0 a 30,19 0 1,0 60,0 Z'
nose='M 141,139 a 13,7 0 1,0 -26,0 a 13,7 0 1,0 26,0 Z'
fold='M 179,166 L 213,166 L 179,197 Z'
for name,color,small in [('mark-ink','#24212b',False),('mark-paper','#f7f2e8',False),('mark-black','#000000',False),('mark-small','#24212b',True)]:
 holes=eyes+fold+(muzzle if not small else 'M 143,140 Q 128,160 113,140 Q 113,134 128,134 Q 143,134 143,140 Z')
 detail=nose if not small else ''
 svg=f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="12 12 232 216" role="img" aria-labelledby="title"><title id="title">Dashless folded-cheek bear mark</title><path fill="{color}" fill-rule="evenodd" d="{outer} {holes}"/><path fill="{color}" d="{detail}"/></svg>'''
 (p/(name+'.svg')).write_text(svg)
