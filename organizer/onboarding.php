<?php
// Ticketa organizer onboarding — standalone page.
// Organizer registration and dashboard destinations will be implemented next.
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#12b99e">
<title>Organizer Onboarding | TICKETA</title>
<style>
:root{--teal:#12b99e;--dark:#079b84;--ink:#222426;--muted:#7c8387;--line:#e5ecec}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;font-family:Inter,"Segoe UI",Arial,sans-serif;color:var(--ink);background:var(--teal)}
button,a{font:inherit}
.shell{min-height:100vh;padding:26px clamp(18px,4.8vw,76px) 42px;display:flex;flex-direction:column;background:radial-gradient(ellipse at 20% 12%,rgba(255,255,255,.08),transparent 40%),linear-gradient(135deg,#15b99f,#08b69a)}
.topbar{width:100%;max-width:1500px;margin:0 auto 28px;display:flex;justify-content:space-between;align-items:center;gap:20px}
.brand{display:inline-flex;align-items:center;gap:12px;color:#fff;text-decoration:none;font-size:clamp(24px,2.7vw,36px);font-weight:700;letter-spacing:-.8px}
.mark{width:42px;height:34px;border:4px solid currentColor;border-radius:5px;display:grid;place-items:center;position:relative;font-size:22px;line-height:1;font-weight:800}
.mark:before,.mark:after{content:"";position:absolute;top:8px;width:7px;height:10px;background:#12b99e;border-top:3px solid #fff;border-bottom:3px solid #fff}
.mark:before{left:-4px}.mark:after{right:-4px}
.skip{border:0;padding:10px 4px;background:transparent;color:rgba(255,255,255,.9);cursor:pointer;font-size:14px}
.card{width:100%;max-width:1500px;min-height:min(720px,calc(100vh - 140px));margin:0 auto;padding:clamp(30px,5vw,70px) clamp(24px,4vw,58px) 30px;border:1px solid rgba(255,255,255,.7);border-radius:13px;background:#fff;box-shadow:0 12px 36px rgba(0,85,72,.08);display:flex;flex-direction:column}
.slides{position:relative;flex:1;min-height:390px}
.slide{display:none;grid-template-columns:minmax(0,.95fr) minmax(0,1.05fr);align-items:center;gap:clamp(22px,4vw,60px);height:100%;min-height:390px;animation:appear .28s ease both}
.slide.active{display:grid}
@keyframes appear{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
.copy{position:relative;z-index:1}.copy h1{margin:0 0 26px;font-size:clamp(29px,3.15vw,44px);line-height:1.28;letter-spacing:-1.5px;font-weight:600}.copy p{max-width:570px;margin:0;color:var(--muted);font-size:clamp(15px,1.3vw,18px);line-height:1.85}
.visual{min-width:0;display:grid;place-items:center;padding:8px}
.window{width:min(100%,570px);border:1.5px solid #d8e1e3;border-radius:17px;overflow:hidden;background:#fff;box-shadow:0 18px 40px rgba(24,78,73,.10)}
.window-top{min-height:46px;padding:0 18px;border-bottom:1px solid #e6ecec;display:flex;align-items:center;justify-content:space-between;gap:12px}
.mini-brand{display:flex;align-items:center;gap:8px;color:#333;font-size:12px;font-weight:800}.mini-mark{width:20px;height:17px;border:2px solid var(--dark);border-radius:3px;color:var(--dark);display:grid;place-items:center;font-size:11px}
.window-dots{display:flex;gap:5px}.window-dots i{width:7px;height:7px;border-radius:50%;background:#d6e1e1}
.window-body{padding:18px}.welcome{margin:0 0 14px;color:#d99b31;font-size:clamp(15px,1.7vw,21px);font-weight:700}
.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:12px}.stat{min-height:72px;padding:11px 9px;border:1px solid #dce5e6;border-radius:10px;box-shadow:0 3px 5px rgba(20,60,60,.05)}.stat small{color:#707a7d;font-size:10px;line-height:1.4}.stat strong{display:block;margin-top:7px;font-size:clamp(11px,1.2vw,15px)}
.chart{padding:14px 12px 8px;border:1px solid #dce5e6;border-radius:10px}.chart-title{display:flex;justify-content:space-between;gap:10px;margin-bottom:12px;font-size:11px;font-weight:650}.chart-title span:last-child{color:var(--dark);font-size:9px}.chart-area{height:115px;overflow:hidden;background:repeating-linear-gradient(to bottom,transparent 0,transparent 27px,#e7eeee 28px)}.chart-area svg{width:100%;height:100%;display:block}
.event-row{display:flex;align-items:center;gap:11px;padding:9px;border:1px solid #e5ecec;border-radius:9px;margin-top:10px}.thumb{flex:0 0 43px;height:39px;border-radius:7px;background:linear-gradient(135deg,#0ca88f,#b8efe2);display:grid;place-items:center;color:#fff;font-size:19px}.event-info{min-width:0;flex:1}.event-info strong{display:block;font-size:11px;margin-bottom:5px}.event-info small{display:block;color:#778184;font-size:9px}.tiny{border:0;border-radius:5px;padding:7px 8px;background:var(--teal);color:#fff;font-size:9px;white-space:nowrap}
.ticket-event{display:grid;grid-template-columns:90px minmax(0,1fr);gap:13px;align-items:center;margin-bottom:15px}.poster{height:82px;border-radius:9px;background:radial-gradient(circle at 60% 30%,rgba(255,255,255,.55),transparent 16%),linear-gradient(140deg,#0b8e7b,#8de3d2 50%,#143f48);display:grid;place-items:center;color:white;font-size:30px}.ticket-event h3{margin:0 0 9px;font-size:16px}.detail{padding:8px 10px;margin-top:6px;border:1px solid #e1e8e8;border-radius:7px;color:#576164;font-size:10px}
.section-title{display:flex;align-items:center;justify-content:space-between;margin:18px 0 9px;font-size:12px;font-weight:700}.section-title span:last-child{color:var(--dark);font-weight:500}
.ticket-type{display:flex;align-items:center;gap:9px;padding:11px;border:1px solid #e2e9e9;border-radius:8px;margin-top:8px;font-size:11px}.ticket-symbol{width:23px;height:23px;border-radius:6px;display:grid;place-items:center;background:#fff0bc;color:#b98510}.ticket-type strong{margin-left:auto;font-size:12px}
.payout{display:flex;align-items:center;gap:12px;padding:16px;border-radius:11px;background:#effaf7;margin-bottom:13px}.payout-icon{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;background:var(--dark);color:#fff;font-size:23px}.payout small{display:block;color:#6d797b;font-size:10px}.payout strong{display:block;margin-top:5px;font-size:19px}.payout-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 2px;border-bottom:1px solid #e7eeee;font-size:11px}.status{color:var(--dark);font-weight:700}
.attendee{display:flex;align-items:center;gap:11px;padding:10px;border:1px solid #e4ebeb;border-radius:9px;margin-top:7px}.avatar{width:32px;height:32px;border-radius:50%;display:grid;place-items:center;background:#dff7f0;color:#087f6c;font-size:12px;font-weight:800}.attendee-name{flex:1;font-size:11px;font-weight:650}.check{color:var(--dark);font-size:18px;font-weight:800}
.scan{margin-top:12px;padding:13px;border:1px solid #dce6e6;border-radius:10px;text-align:center;background:#f9fdfc}.qr{display:inline-grid;grid-template-columns:repeat(5,7px);gap:2px;padding:6px;background:#fff;border:1px solid #e0e8e8}.qr i{width:7px;height:7px;background:#174f49}.qr i:nth-child(2n),.qr i:nth-child(7n){background:#fff}.scan small{display:block;margin-top:7px;color:#6d797b;font-size:9px}
.footer{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);align-items:center;gap:20px;margin-top:28px;min-height:56px}.footer .back{grid-column:1;justify-self:start}.footer .dots{grid-column:2;grid-row:1;position:static;transform:none}.footer .continue{grid-column:3;justify-self:end}.back{justify-self:start;border:1px solid #e3eaea;border-radius:8px;padding:12px 18px;color:#465154;background:#fff;cursor:pointer}.back[hidden]{display:none}
.dots{display:flex;justify-content:center;gap:13px}.dot{width:15px;height:15px;border:0;border-radius:50%;padding:0;background:#d9dcdc;cursor:pointer;transition:background .2s,transform .2s}.dot.active{background:var(--teal);transform:scale(1.06)}
.continue{justify-self:end;min-width:205px;min-height:56px;padding:14px 24px;border:0;border-radius:6px;background:var(--teal);color:#fff;font-size:16px;font-weight:600;cursor:pointer;box-shadow:0 4px 10px rgba(18,185,158,.14);transition:background .2s,transform .2s}.continue:hover{background:var(--dark);transform:translateY(-1px)}
.note{margin:18px 0 0;color:#899193;font-size:11px;text-align:center}
button:focus-visible,a:focus-visible{outline:3px solid #145f55;outline-offset:3px}
@media(max-width:900px){.shell{padding:20px}.card{min-height:auto;padding:30px 26px 26px}.slides{min-height:0}.slide,.slide.active{grid-template-columns:1fr;gap:22px;min-height:0}.copy h1{max-width:650px;margin-bottom:14px}.copy p{max-width:650px}.visual{padding:0}.window{max-width:590px}.footer{margin-top:25px}}
@media(max-width:560px){.shell{padding:16px 12px 20px}.topbar{margin-bottom:18px}.brand{font-size:25px;gap:9px}.mark{width:35px;height:30px;font-size:19px}.card{padding:27px 17px 20px;border-radius:11px}.copy h1{font-size:29px;letter-spacing:-.8px}.copy p{font-size:15px;line-height:1.7}.window-body{padding:12px}.window-top{min-height:40px;padding:0 12px}.stats{gap:5px}.stat{min-height:66px;padding:8px 6px}.stat small{font-size:9px}.stat strong{font-size:11px}.chart-area{height:85px}.ticket-event{grid-template-columns:72px minmax(0,1fr)}.poster{height:72px}.footer{display:grid;grid-template-columns:auto minmax(0,1fr) auto;grid-template-rows:auto auto;gap:17px 10px;margin-top:22px;min-height:0;padding-top:0}.footer .dots{grid-column:1/-1;grid-row:1;justify-self:center;position:static;transform:none}.footer .back{grid-column:1;grid-row:2}.footer .continue{grid-column:3;grid-row:2;justify-self:end;min-width:0;width:auto;min-height:50px;padding:12px 20px}.back{padding:12px 13px}.dot{width:12px;height:12px}}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{animation-duration:.01ms!important;transition-duration:.01ms!important}}
</style>
</head>
<body>
<div class="shell">
<header class="topbar">
<a class="brand" href="../index.php" aria-label="Ticketa home"><span class="mark" aria-hidden="true">T</span><span>TICKETA</span></a>
<button type="button" class="skip" id="skip">Skip</button>
</header>
<main class="card" aria-label="Organizer onboarding">
<div class="slides">
<section class="slide active" data-slide="0" aria-hidden="false">
<div class="copy"><h1>Sell Tickets. Manage Events. Get paid — All in One Place.</h1><p>Create events, sell tickets, manage attendees, track sales, and receive payouts — all from one powerful dashboard.</p></div>
<div class="visual" aria-label="Illustration of the organizer dashboard"><div class="window">
<div class="window-top"><div class="mini-brand"><span class="mini-mark">T</span>TICKETA</div><div class="window-dots"><i></i><i></i><i></i></div></div>
<div class="window-body"><p class="welcome">Welcome to your organizer space</p><div class="stats"><div class="stat"><small>Total revenue</small><strong>— XOF</strong></div><div class="stat"><small>Tickets sold</small><strong>—</strong></div><div class="stat"><small>Upcoming events</small><strong>—</strong></div></div>
<div class="chart"><div class="chart-title"><span>Sales performance</span><span>Overview</span></div><div class="chart-area"><svg viewBox="0 0 500 115" preserveAspectRatio="none" aria-hidden="true"><path d="M0 96 C45 94 55 78 92 83 S145 64 185 70 S238 39 280 48 S338 58 370 32 S435 40 500 10 L500 115 L0 115Z" fill="#dff7f0"/><path d="M0 96 C45 94 55 78 92 83 S145 64 185 70 S238 39 280 48 S338 58 370 32 S435 40 500 10" fill="none" stroke="#55c7ae" stroke-width="3"/></svg></div></div>
<div class="event-row"><div class="thumb">♫</div><div class="event-info"><strong>Your first event will appear here</strong><small>Publish an event to get started</small></div><span class="tiny">Create</span></div></div></div></div>
</section>
<section class="slide" data-slide="1" aria-hidden="true">
<div class="copy"><h1>Create &amp; Publish Events</h1><p>Set up your event page, add tickets, and go live in minutes.</p></div>
<div class="visual" aria-label="Illustration of event and ticket setup"><div class="window">
<div class="window-top"><div class="mini-brand"><span class="mini-mark">T</span>TICKETA</div><div class="window-dots"><i></i><i></i><i></i></div></div>
<div class="window-body"><div class="ticket-event"><div class="poster">♫</div><div><h3>Your event title</h3><div class="detail">▣ &nbsp; Choose date and time</div><div class="detail">⌖ &nbsp; Add event location</div></div></div>
<div class="section-title"><span>Ticket types</span><span>+ Add ticket</span></div><div class="ticket-type"><span class="ticket-symbol">◆</span><span>General Admission</span><strong>Set price</strong></div><div class="ticket-type"><span class="ticket-symbol" style="background:#e1f5ff;color:#3183a0">★</span><span>VIP</span><strong>Set price</strong></div><div style="margin-top:17px;text-align:center"><span class="tiny" style="display:inline-block;padding:10px 25px;font-size:11px">Save event draft</span></div></div></div></div>
</section>
<section class="slide" data-slide="2" aria-hidden="true">
<div class="copy"><h1>Get Paid &amp; Track Performance</h1><p>Review ticket sales and revenue, and manage payout details from one place. Available payout options depend on your configured payment setup.</p></div>
<div class="visual" aria-label="Illustration of sales and payout reporting"><div class="window">
<div class="window-top"><div class="mini-brand"><span class="mini-mark">T</span>TICKETA</div><div class="window-dots"><i></i><i></i><i></i></div></div>
<div class="window-body"><div class="payout"><div class="payout-icon">↗</div><div><small>Sales overview</small><strong>— XOF</strong><small>Real figures appear when you make sales</small></div></div>
<div class="chart"><div class="chart-title"><span>Revenue performance</span><span>Report</span></div><div class="chart-area"><svg viewBox="0 0 500 115" preserveAspectRatio="none" aria-hidden="true"><path d="M0 104 C60 102 70 80 120 84 S190 58 235 65 S300 30 345 43 S420 20 500 8 L500 115 L0 115Z" fill="#dff7f0"/><path d="M0 104 C60 102 70 80 120 84 S190 58 235 65 S300 30 345 43 S420 20 500 8" fill="none" stroke="#55c7ae" stroke-width="3"/></svg></div></div>
<div class="section-title"><span>Payment activity</span><span style="color:#778184">When available</span></div><div class="payout-row"><span>Ticket sales</span><span class="status">Tracked in dashboard</span></div><div class="payout-row"><span>Payout status</span><span class="status">When configured</span></div></div></div></div>
</section>
<section class="slide" data-slide="3" aria-hidden="true">
<div class="copy"><h1>Manage Attendees &amp; Check-Ins Effortlessly</h1><p>Keep attendee lists organized and make event entry smoother with ticket verification and check-in tools as your events grow.</p></div>
<div class="visual" aria-label="Illustration of attendee management"><div class="window">
<div class="window-top"><div class="mini-brand"><span class="mini-mark">T</span>TICKETA</div><div class="window-dots"><i></i><i></i><i></i></div></div>
<div class="window-body"><div class="section-title" style="margin-top:0"><span>Attendee list</span><span style="color:#788486">Sample preview</span></div>
<div class="attendee"><span class="avatar">A</span><span class="attendee-name">Attendee name</span><span class="check">✓</span></div><div class="attendee"><span class="avatar" style="background:#fff0c7;color:#9c7215">B</span><span class="attendee-name">Attendee name</span><span class="check">✓</span></div><div class="attendee"><span class="avatar" style="background:#e9e5ff;color:#6555a6">C</span><span class="attendee-name">Attendee name</span><span class="check">✓</span></div><div class="attendee"><span class="avatar" style="background:#ffe7e1;color:#a45745">D</span><span class="attendee-name">Attendee name</span><span class="check">✓</span></div>
<div class="scan"><div class="qr" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div><small>Ticket verification preview</small></div></div></div></div>
</section>
</div>
<footer class="footer">
<button type="button" class="back" id="back" hidden>← Back</button>
<div class="dots" aria-label="Onboarding progress"><button type="button" class="dot active" data-dot="0" aria-label="Go to screen 1" aria-current="step"></button><button type="button" class="dot" data-dot="1" aria-label="Go to screen 2"></button><button type="button" class="dot" data-dot="2" aria-label="Go to screen 3"></button><button type="button" class="dot" data-dot="3" aria-label="Go to screen 4"></button></div>
<button type="button" class="continue" id="continue">Continue</button>
</footer>
<p class="note">Organizer setup · You can review your details before publishing an event.</p>
</main>
</div>
<script>
(function(){
const slides=[...document.querySelectorAll('[data-slide]')];
const dots=[...document.querySelectorAll('[data-dot]')];
const back=document.getElementById('back');
const next=document.getElementById('continue');
let current=0;
// Organizer register.php is the next planned implementation stage.
const registrationUrl='register.php';
function show(index){
 current=Math.max(0,Math.min(index,slides.length-1));
 slides.forEach((s,i)=>{const active=i===current;s.classList.toggle('active',active);s.setAttribute('aria-hidden',String(!active));});
 dots.forEach((d,i)=>{const active=i===current;d.classList.toggle('active',active);if(active)d.setAttribute('aria-current','step');else d.removeAttribute('aria-current');});
 back.hidden=current===0;
 next.textContent=current===slides.length-1?'Get Started':'Continue';
}
next.addEventListener('click',()=>current<slides.length-1?show(current+1):location.assign(registrationUrl));
back.addEventListener('click',()=>show(current-1));
document.getElementById('skip').addEventListener('click',()=>location.assign(registrationUrl));
dots.forEach(d=>d.addEventListener('click',()=>show(Number(d.dataset.dot))));
document.addEventListener('keydown',e=>{if(e.key==='ArrowRight'&&current<slides.length-1)show(current+1);if(e.key==='ArrowLeft'&&current>0)show(current-1);});
show(0);
})();
</script>
</body>
</html>
