"""V5Q25 recording countdown checks: UI selections and separation from recording duration."""
from pathlib import Path
import re
R=Path(__file__).resolve().parents[1]
html=(R/'proof.php').read_text()
js=(R/'public/claim-capture.js').read_text()
checks=[]
def ck(name,ok):checks.append((name,bool(ok)))
header=html.split('</header>',1)[0]
ck('Pre-record countdown selector remains visible in proof header','id="capture-precount-seconds"' in header)
options=html.split('id="capture-precount-seconds"',1)[1].split('</select>',1)[0]
ck('Countdown choices are exactly 3, 5, 10, 30 seconds',re.findall(r'<option value="(\d+)"',options)==['3','5','10','30'])
ck('Pre-record default is 3 seconds','<option value="3" selected>' in options)
ck('Recording clock has separate duration selector','id="capture-record-seconds"' in html)
ck('Proof stage includes clearly labeled count display',all(x in html for x in ['id="proof-stage-timer"','id="proof-stage-timer-label"','id="proof-stage-timer-seconds"']))
ck('Pre-countdown selected duration is validated', '[3,5,10,30].includes(seconds)?seconds:3' in js)
ck('Count-down is awaited before MediaRecorder.start',js.index('await precountPromise')<js.index('r.start(250)',js.index('async function record(onlyIds')))
ck('Stop cancels pending countdown before recording',"if(precountAbort){precountAbort.abort();" in js)
ck('Rotating cameras also use configured countdown','countdown:preCountSeconds()' in js)
for n,v in checks:print(('PASS ' if v else 'FAIL ')+n)
print('V5Q25 recording countdown:',sum(v for _,v in checks),'/',len(checks))
if not all(v for _,v in checks):raise SystemExit(1)
