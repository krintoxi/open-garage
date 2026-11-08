<?php
// OBD-II diagnostic knowledge (2026-11-08): what a code means, how serious it is, ranked causes, DIY checks,
// mechanic test plans, typical costs, cross-code reasoning and well-known vehicle-specific patterns.
// Pure data + functions, no database. Covers the SAE generic codes by family (titles generated from the standard
// numbering) with hand-written knowledge per family, and explains any other code by its structure.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

const OBD_DRIVE = [
    'ok' => ['Fine to drive', 'Safe to drive. Fix it when convenient so it does not hide a new code.'],
    'soon' => ['Drive, fix soon', 'Usually safe for normal driving. Get it fixed within a few weeks: left alone it costs fuel or damages other parts.'],
    'careful' => ['Limit driving', 'Drive only as needed and gently. Fix within days: it can strand you or damage expensive parts.'],
    'stop' => ['Stop driving', 'Do not keep driving. Risk of engine/transmission damage, fire or loss of control. Tow it or fix it first.'],
];

/** Knowledge per fault family. Strings use plain language in plain/diy, shop language in set/tests. */
function obd_kb(): array {
    static $kb = null;
    if ($kb !== null) return $kb;
    $kb = [
    'lean' => ['sev' => 3, 'drive' => 'soon', 'cost' => [20, 700], 'diff' => 2,
        'plain' => "The engine computer measured less fuel than the air coming in needs, and has hit its limit adding fuel to correct it. Usually air is getting in where it should not (a cracked hose) or the airflow sensor is misreading.",
        'causes' => [["Vacuum leak: cracked intake boot after the airflow sensor, PCV valve or hose, brake booster hose, intake manifold gasket", 3], ["Dirty or failing mass airflow (MAF) sensor reading low", 3], ["Low fuel pressure: weak fuel pump, clogged filter, failing regulator", 2], ["Exhaust leak ahead of the upstream O2 sensor pulling in air", 2], ["Clogged or leaking fuel injectors", 1], ["Failing upstream O2 / air-fuel sensor (rare cause of this code)", 1]],
        'diy' => ["With the engine idling, listen around the intake for a hiss and look over the rubber boot between the air filter box and the throttle for cracks (squeeze it, cracks hide in folds).", "Clean the MAF sensor with MAF-cleaner spray only (not brake or carb cleaner), let it dry fully, clear the code, drive a few days.", "Check the oil filler cap and dipstick are seated: an open one is a vacuum leak on many engines.", "If fuel economy dropped and the car hesitates on hills, suspect fuel pressure and let a shop test it."],
        'set' => "Long-term fuel trim at the adaptation limit (typically +20-25%) for a set time in closed loop.",
        'tests' => ["Read STFT+LTFT at warm idle and at 2,500 rpm no load. Lean at idle that improves with rpm = vacuum leak. Lean everywhere or worse under load = fuel delivery or MAF under-reading.", "Smoke-test the intake and crankcase (PCV) system.", "MAF g/s at warm idle should be roughly the engine size in litres (3.0 L ~ 3 g/s). Low reading + lean = MAF.", "Fuel pressure and volume test against spec at idle and under load (or rail pressure PID on direct injection).", "Propane/carb-cleaner enrichment test around suspect areas while watching STFT."],
        'related' => ['P0174', 'P0171', 'P0101', 'P0300', 'P2187', 'P0507']],
    'rich' => ['sev' => 3, 'drive' => 'soon', 'cost' => [30, 600], 'diff' => 2,
        'plain' => "The engine is getting more fuel than it needs and the computer has hit its limit pulling fuel out. Wastes fuel, can foul spark plugs and overheat the catalytic converter.",
        'causes' => [["Leaking fuel injector(s)", 2], ["Fuel pressure too high: failed regulator (or fuel in the regulator vacuum line)", 2], ["EVAP purge valve stuck open, feeding fuel vapour at idle", 2], ["MAF sensor over-reading or contaminated", 2], ["Coolant temperature sensor reading too cold", 1], ["Restricted air filter or intake", 1], ["Oil diluted with fuel (crankcase vapour)", 1]],
        'diy' => ["Check the air filter: a badly clogged one can push trims rich.", "Smell for raw fuel at the exhaust or around the engine.", "Pull the vacuum line off the fuel pressure regulator (if it has one): fuel in the line = regulator diaphragm failed."],
        'set' => "Long-term fuel trim at the negative adaptation limit (about -20 to -25%) in closed loop.",
        'tests' => ["Pinch/unplug the purge line at idle: trims returning toward 0 = purge valve leaking through.", "Fuel pressure and leak-down test (pressure that drops fast after shut-off = leaking injector or regulator).", "Compare ECT PID to actual temperature with an IR thermometer.", "Injector balance test or contribution test where the scan tool supports it."],
        'related' => ['P0175', 'P0172', 'P0441', 'P0496', 'P0118']],
    'trim_system' => ['sev' => 3, 'drive' => 'soon', 'cost' => [30, 600], 'diff' => 2,
        'plain' => "The fuel mixture correction on this bank is outside the range the computer accepts. Treat it like a lean/rich code: look at the fuel trims to see which way it is off.",
        'causes' => [["Vacuum leak or exhaust leak", 2], ["MAF sensor", 2], ["Fuel pressure", 2], ["Injector fault", 1], ["O2 / air-fuel sensor", 1]],
        'diy' => ["Look for cracked intake hoses and clean the MAF.", "Read the fuel trims with a scan app: positive = lean, negative = rich."],
        'tests' => ["Same flow as P0171/P0172 depending on the trim direction."],
        'related' => ['P0171', 'P0172', 'P0174', 'P0175']],
    'misfire_random' => ['sev' => 4, 'drive' => 'careful', 'cost' => [40, 1200], 'diff' => 2,
        'plain' => "The engine is misfiring on more than one cylinder or the misfires move around. If the check-engine light is flashing, the misfire is bad enough to damage the catalytic converter: slow down and get it checked.",
        'causes' => [["Worn spark plugs (especially past their interval)", 3], ["Ignition coil(s) failing", 2], ["Vacuum leak or lean condition", 2], ["Low fuel pressure / clogged injectors", 2], ["Low compression or worn valve train", 1], ["Timing chain/belt stretched or jumped", 1], ["Bad fuel or water in fuel", 1]],
        'diy' => ["If the light flashes: do not drive hard; get it fixed in days, not weeks.", "If plugs are older than the service interval, replace them (and inspect the coil boots for cracks or carbon tracks).", "Note when it happens: cold start, idle, under load, in rain. It narrows the cause a lot.", "Check for other codes: a lean code with it points at air/fuel, not ignition."],
        'set' => "Crankshaft speed fluctuation over a 200/1000-revolution window above the catalyst-damage or emissions threshold, misfire on more than one cylinder.",
        'tests' => ["Read misfire counters per cylinder (Mode 6) to see which cylinders participate.", "Check fuel trims: lean on all cylinders = air/fuel cause common to the engine.", "Compression and leak-down test if counts are concentrated on cylinders sharing a bank or a valve train.", "Check cam/crank correlation values for timing stretch.", "Inspect plugs: colour and gap tell lean, oil fouling or wear."],
        'related' => ['P0301', 'P0171', 'P0174', 'P0420', 'P0016']],
    'misfire_cyl' => ['sev' => 4, 'drive' => 'careful', 'cost' => [40, 900], 'diff' => 2,
        'plain' => "One cylinder is not firing properly. Most often the spark plug or the ignition coil on that cylinder; a flashing check-engine light means stop driving hard because it can ruin the catalytic converter.",
        'causes' => [["Ignition coil on that cylinder", 3], ["Spark plug worn, cracked or oil-fouled", 3], ["Fuel injector on that cylinder clogged or failed", 2], ["Coil/injector connector or wiring", 1], ["Low compression on that cylinder: valve, rings, head gasket", 1], ["Vacuum leak near that cylinder's intake runner", 1]],
        'diy' => ["The classic DIY test: swap that cylinder's coil with a neighbour's, clear the code, drive. If the misfire moves to the other cylinder, the coil is bad (a coil is often a 30-90 minute DIY).", "Pull the plug and look: oily, cracked porcelain or a big worn gap means replace the set.", "Check the coil boot for cracks or black carbon tracks and the connector for oil or corrosion."],
        'set' => "Misfire counts on the named cylinder above threshold (crank acceleration method).",
        'tests' => ["Swap coil (and plug) to an adjacent cylinder and see if the misfire follows.", "Injector: swap or use injector balance / noid light; listen with a stethoscope.", "Compression test on that cylinder, then leak-down if low (listen at intake, exhaust, oil filler, radiator).", "Check for oil in the plug well (valve cover / tube seals)."],
        'related' => ['P0300', 'P0351', 'P0201']],
    'injector_circuit' => ['sev' => 3, 'drive' => 'careful', 'cost' => [60, 700], 'diff' => 3,
        'plain' => "The computer sees an electrical problem in the circuit of one fuel injector (broken wire, bad plug or a failed injector coil). That cylinder may misfire.",
        'causes' => [["Injector connector loose, corroded or broken", 3], ["Injector coil open or shorted", 2], ["Wiring harness damage (heat, rodents)", 2], ["Engine computer driver (rare)", 1]],
        'diy' => ["Look at the injector connector on that cylinder: firmly seated, lock tab intact, no green corrosion.", "Check the harness near hot exhaust parts and look for rodent chewing."],
        'tests' => ["Measure injector resistance (typically 11-18 ohm high-impedance) and compare with the others.", "Back-probe with a noid light or scope the injector control signal while cranking.", "Check the injector supply voltage with key on."],
        'related' => ['P0301', 'P0300']],
    'injector_balance' => ['sev' => 3, 'drive' => 'soon', 'cost' => [100, 900], 'diff' => 3,
        'plain' => "One injector is delivering noticeably more or less fuel than the others (common on diesels and direct-injection engines).",
        'causes' => [["Worn or sticking injector", 3], ["Injector return leak (diesel)", 2], ["Low compression on that cylinder", 1]],
        'diy' => ["Note rough idle or smoke; the repair is mostly shop work."],
        'tests' => ["Injector contribution / balance rates with a capable scan tool.", "Return-flow (leak-off) test on diesels.", "Compression test on the cylinder."],
        'related' => ['P0300']],
    'coil_circuit' => ['sev' => 3, 'drive' => 'careful', 'cost' => [50, 400], 'diff' => 2,
        'plain' => "The computer sees an electrical problem with one ignition coil's circuit. That cylinder will usually misfire.",
        'causes' => [["Ignition coil failed internally", 3], ["Coil connector loose, corroded or oil-soaked", 2], ["Wiring between coil and computer", 1], ["Engine computer coil driver", 1]],
        'diy' => ["Swap that coil with a neighbour's. If the code follows to the new cylinder, replace the coil.", "Inspect the connector for oil (leaking valve cover or tube seals) and corrosion."],
        'tests' => ["Check coil power and ground with key on; scope the primary trigger signal.", "Swap-test as above; check secondary output with a spark tester."],
        'related' => ['P0300', 'P0301']],
    'o2_heater' => ['sev' => 2, 'drive' => 'ok', 'cost' => [60, 350], 'diff' => 2,
        'plain' => "The heater inside an oxygen sensor is not working. The heater gets the sensor up to temperature quickly; without it emissions rise a little after a cold start. The engine runs normally.",
        'causes' => [["Oxygen sensor heater burnt out (replace the sensor)", 3], ["Blown fuse for the O2 heaters (if several heater codes)", 2], ["Connector or wiring damage near the exhaust", 2], ["Computer heater driver (rare)", 1]],
        'diy' => ["If more than one heater code is set, check the fuse feeding the O2 heaters first.", "An O2 sensor is a common DIY with an O2-sensor socket and penetrating oil; let the exhaust cool fully."],
        'tests' => ["Measure heater resistance at the sensor (typically 2-14 ohm depending on design).", "Check heater supply voltage with key on and the ground/control side from the computer."],
        'related' => ['P0135', 'P0141', 'P0030']],
    'o2_circuit' => ['sev' => 2, 'drive' => 'soon', 'cost' => [60, 350], 'diff' => 2,
        'plain' => "There is an electrical problem with an oxygen sensor or its wiring. The computer may fall back to a default fuel mixture.",
        'causes' => [["Oxygen sensor failed", 3], ["Wiring or connector damaged by exhaust heat", 2], ["Exhaust leak near the sensor", 1]],
        'diy' => ["Inspect the sensor wiring along the exhaust for melted insulation or a disconnected plug.", "Replacing a sensor is a common DIY; buy the correct upstream or downstream part."],
        'tests' => ["Check reference/signal voltages at the connector; compare live data to a known-good sensor.", "Check for exhaust leaks upstream of the sensor."],
        'related' => ['P0131', 'P0133', 'P0171']],
    'o2_perf' => ['sev' => 2, 'drive' => 'soon', 'cost' => [60, 400], 'diff' => 2,
        'plain' => "An oxygen sensor responds too slowly or does not switch the way it should. Old sensors get lazy; it can also be a symptom of a mixture problem elsewhere.",
        'causes' => [["Aged or contaminated oxygen sensor (oil, coolant, silicone)", 3], ["Exhaust leak near the sensor", 2], ["Fuel mixture problem making the sensor look wrong", 1]],
        'diy' => ["If the sensor is original on a high-mileage car, replacement is the usual fix.", "Look for white or black crust on the old sensor: coolant or oil contamination points at an engine problem."],
        'tests' => ["Graph the sensor: upstream narrow-band should switch 0.1-0.9 V several times a second at 2,500 rpm.", "Snap-throttle response time; propane enrichment test.", "For downstream sensors, check that the catalyst is not the real issue."],
        'related' => ['P0420', 'P0171']],
    'o2_low' => ['sev' => 2, 'drive' => 'soon', 'cost' => [60, 400], 'diff' => 2,
        'plain' => "An oxygen sensor reads lean (low voltage) all the time. Either the sensor/wiring is faulty or the engine really is running lean on that side.",
        'causes' => [["Real lean condition: vacuum or exhaust leak", 2], ["Oxygen sensor failed", 2], ["Signal wire shorted to ground", 2]],
        'diy' => ["Check for lean codes (P0171/P0174) too; if present, fix those first.", "Inspect the sensor wiring for chafing."],
        'tests' => ["Drive the sensor rich with propane: no response = sensor/wiring; responds = real lean condition.", "Check for exhaust leaks ahead of the sensor."],
        'related' => ['P0171', 'P0174']],
    'o2_high' => ['sev' => 2, 'drive' => 'soon', 'cost' => [60, 400], 'diff' => 2,
        'plain' => "An oxygen sensor reads rich (high voltage) all the time: either the sensor/wiring or a real rich mixture.",
        'causes' => [["Real rich condition: leaking injector, high fuel pressure, purge valve", 2], ["Oxygen sensor contaminated or failed", 2], ["Signal wire shorted to voltage", 2]],
        'diy' => ["Check for rich codes (P0172/P0175) and a fuel smell from the exhaust."],
        'tests' => ["Create a vacuum leak: no response = sensor/wiring; responds = real rich condition.", "Check signal circuit for short to voltage."],
        'related' => ['P0172', 'P0175']],
    'o2_noact' => ['sev' => 2, 'drive' => 'soon', 'cost' => [60, 400], 'diff' => 2,
        'plain' => "An oxygen sensor is not producing a signal at all. Usually a dead sensor, a broken wire, or a heater failure that keeps it cold.",
        'causes' => [["Sensor failed", 3], ["Open circuit in wiring/connector", 2], ["Heater not working (sensor never warms up)", 2]],
        'diy' => ["Check the connector and wiring; look for heater codes too."],
        'tests' => ["Check for heater operation and signal voltage; scope the sensor."],
        'related' => ['P0135']],
    'airfuel_stuck' => ['sev' => 2, 'drive' => 'soon', 'cost' => [80, 450], 'diff' => 2,
        'plain' => "The front oxygen / air-fuel sensor reading is stuck lean or stuck rich. The sensor may be failing or the mixture really is off.",
        'causes' => [["Upstream air-fuel / O2 sensor aged or failed", 3], ["Real lean (vacuum/exhaust leak) or rich (injector, pressure) condition", 2], ["Wiring", 1]],
        'diy' => ["Check for lean/rich codes and fix those first; then the sensor is a common DIY."],
        'tests' => ["Compare sensor reading with fuel trims; enrichment/lean test for response."],
        'related' => ['P0171', 'P0172']],
    'post_cat_trim' => ['sev' => 2, 'drive' => 'soon', 'cost' => [80, 1500], 'diff' => 3,
        'plain' => "The sensor after the catalytic converter shows the mixture is off and the computer cannot correct it. Often a failing upstream sensor, an exhaust leak, or a converter on its way out.",
        'causes' => [["Upstream air-fuel sensor drifting", 2], ["Exhaust leak between the sensors", 2], ["Catalytic converter degraded", 2], ["Downstream sensor failing", 1]],
        'diy' => ["Listen for exhaust ticking or puffing at a cold start (leaks)."],
        'tests' => ["Compare upstream and downstream sensor behaviour; check for leaks with smoke at the tailpipe."],
        'related' => ['P0420', 'P0430']],
    'catalyst' => ['sev' => 2, 'drive' => 'soon', 'cost' => [150, 2500], 'diff' => 3,
        'plain' => "The catalytic converter on this side is not cleaning the exhaust as well as it should, measured by the oxygen sensor after it. The car runs fine but will fail an emissions test. Do not jump straight to a new converter: leaks, sensors and misfires fake this code.",
        'causes' => [["Catalytic converter worn out (high mileage) or damaged by misfire/oil burning", 3], ["Exhaust leak near the downstream O2 sensor", 2], ["Downstream O2 sensor lazy or failed", 2], ["Engine misfire or rich running that overheated the converter", 2], ["Oil or coolant consumption coating the converter", 1]],
        'diy' => ["Fix any misfire, lean or rich codes first; a new converter will die again otherwise.", "Check for exhaust leaks (ticking at cold start, soot marks at joints).", "Do not buy a cheap universal converter in states with CARB rules (California and others): it must be legal for your car."],
        'set' => "Rear O2 sensor switching approaches the front sensor's activity during the catalyst monitor (oxygen storage below threshold).",
        'tests' => ["Graph front vs rear O2 at 2,500 rpm warm: rear should stay steady (~0.6-0.7 V narrow-band).", "Check Mode 6 catalyst monitor test value vs limit to see how close it is.", "Temperature test: outlet should run hotter than inlet once warm.", "Smoke-test the exhaust for leaks ahead of the rear sensor."],
        'related' => ['P0300', 'P0171', 'P0138', 'P2097']],
    'evap_small' => ['sev' => 1, 'drive' => 'ok', 'cost' => [0, 450], 'diff' => 2,
        'plain' => "The fuel vapour system has a small leak, so fuel fumes can escape. No effect on how the car runs. The most common cause by far is the gas cap.",
        'causes' => [["Gas cap loose, worn seal, or aftermarket cap", 3], ["Cracked EVAP hose or loose connection", 2], ["Purge valve or vent valve not sealing", 2], ["Filler neck rusted or charcoal canister cracked", 1]],
        'diy' => ["Tighten the gas cap until it clicks; inspect the rubber seal. A new OEM cap is cheap and fixes a large share of these codes.", "Clear the code and drive normally for a week: the leak test only runs under specific conditions (cold start, fuel level 15-85%)."],
        'set' => "EVAP leak test (engine-off natural vacuum or pump test) detects a leak of about 0.020-0.040 inch.",
        'tests' => ["Smoke-test the EVAP system through the service port.", "Command the vent valve closed and purge open; watch tank pressure hold vacuum.", "Check purge valve for leak-through and vent valve for sticking."],
        'related' => ['P0455', 'P0456', 'P0457', 'P0441']],
    'evap_large' => ['sev' => 2, 'drive' => 'ok', 'cost' => [0, 500], 'diff' => 2,
        'plain' => "The fuel vapour system cannot hold vacuum at all: a large leak. Very often the gas cap was left off or not tightened after filling up.",
        'causes' => [["Gas cap missing or not tightened", 3], ["Disconnected or split EVAP hose", 2], ["Purge valve stuck closed or vent valve stuck open", 2], ["Charcoal canister cracked", 1]],
        'diy' => ["Check the gas cap first. Then look along the hoses from the engine bay purge valve back towards the tank."],
        'tests' => ["Smoke test; command purge/vent valves and check operation; check the tank pressure sensor reading."],
        'related' => ['P0442', 'P0457', 'P0446']],
    'evap_purge' => ['sev' => 2, 'drive' => 'ok', 'cost' => [40, 300], 'diff' => 2,
        'plain' => "The EVAP purge valve (which lets fuel vapour from the charcoal canister be burned in the engine) is not working right or its circuit has a fault. A purge valve stuck open can cause rough idle right after filling up.",
        'causes' => [["Purge valve (solenoid) failed or stuck", 3], ["Connector/wiring", 2], ["Blocked or disconnected purge hose", 1]],
        'diy' => ["Find the purge valve (usually on or near the intake). With the engine off, blowing through it should be blocked; if air passes freely it is stuck open.", "Hard starting right after refuelling is a classic stuck-open purge valve symptom."],
        'tests' => ["Command the valve with the scan tool and check vacuum/flow; check resistance and duty-cycle control."],
        'related' => ['P0441', 'P0496', 'P0172']],
    'evap_vent' => ['sev' => 1, 'drive' => 'ok', 'cost' => [40, 350], 'diff' => 2,
        'plain' => "The EVAP vent valve (near the charcoal canister, usually by the fuel tank) or its circuit has a problem. Can cause trouble filling the tank (pump clicks off early).",
        'causes' => [["Vent valve stuck or failed (dirt/spider webs)", 3], ["Wiring/connector near the tank exposed to road salt", 2], ["Canister clogged with liquid fuel from overfilling", 1]],
        'diy' => ["Stop topping off the tank after the pump clicks: liquid fuel ruins the canister.", "Check the connector at the vent valve for corrosion."],
        'tests' => ["Command the vent closed/open; check resistance, power and control; check the filter/inlet for blockage."],
        'related' => ['P0442', 'P0455']],
    'evap_pressure_sensor' => ['sev' => 1, 'drive' => 'ok', 'cost' => [60, 400], 'diff' => 3,
        'plain' => "The fuel tank pressure sensor (used for the EVAP leak test) is reading out of range or has a circuit fault.",
        'causes' => [["Tank pressure sensor failed", 3], ["Wiring/connector at the tank", 2], ["Blocked vapour line to the sensor", 1]],
        'diy' => ["Remove the gas cap and see if the sensor reading drops to zero with a scan app."],
        'tests' => ["Check 5 V reference, ground and signal; compare reading cap-off (should be ~0)."],
        'related' => ['P0455']],
    'egr' => ['sev' => 2, 'drive' => 'soon', 'cost' => [80, 900], 'diff' => 3,
        'plain' => "The exhaust gas recirculation (EGR) system is not flowing the right amount. Too little causes pinging and high emissions; too much causes rough idle or stalling.",
        'causes' => [["Carbon-clogged EGR passages or valve", 3], ["EGR valve failed or stuck", 2], ["EGR position sensor or vacuum solenoid/hose", 2], ["DPFE sensor (Ford) or its hoses", 1]],
        'diy' => ["On older engines, clogged EGR ports are common and can be cleaned; look up a video for your engine.", "Check the vacuum hoses to the EGR valve for cracks."],
        'tests' => ["Command the EGR open at idle: rpm should drop or stumble.", "Check position sensor feedback vs command; check passages for carbon."],
        'related' => ['P0401', 'P0402']],
    'secondary_air' => ['sev' => 2, 'drive' => 'ok', 'cost' => [150, 1500], 'diff' => 3,
        'plain' => "The secondary air injection system (pumps fresh air into the exhaust right after a cold start to warm the converter) is not working. The engine runs normally, but emissions are higher after cold starts.",
        'causes' => [["Air pump failed (often from water getting in)", 3], ["Check/switching valve stuck or leaking", 2], ["Fuse, relay or wiring", 2], ["Clogged ports in the cylinder head", 1]],
        'diy' => ["Check the pump fuse and relay. Listen for the pump running for a minute after a cold start."],
        'tests' => ["Command the pump; check O2 sensor goes lean when air is injected; inspect the check valve for exhaust damage."],
        'related' => ['P0411', 'P2440']],
    'iac_idle' => ['sev' => 2, 'drive' => 'soon', 'cost' => [0, 400], 'diff' => 2,
        'plain' => "Idle speed is not what the computer wants. Low idle can stall; high idle is often a vacuum leak or a dirty throttle body.",
        'causes' => [["Dirty throttle body / idle air passage", 3], ["Vacuum leak (high idle)", 2], ["Idle air control valve or electronic throttle", 2], ["Needs throttle relearn after battery disconnect", 1]],
        'diy' => ["Clean the throttle body with throttle-body cleaner (engine off; on electronic throttles do not force the plate by hand).", "Do an idle relearn for your car (often: idle 10 minutes with everything off)."],
        'tests' => ["Check commanded vs actual idle, IAC counts / throttle angle at idle, and fuel trims for a vacuum leak."],
        'related' => ['P0171', 'P2111']],
    'vss' => ['sev' => 2, 'drive' => 'soon', 'cost' => [40, 400], 'diff' => 2,
        'plain' => "The computer is not getting a good vehicle speed signal. The speedometer, cruise control and shifting can act up.",
        'causes' => [["Speed sensor failed", 3], ["Wiring/connector", 2], ["ABS wheel speed sensor (on cars that use ABS for speed)", 2], ["Instrument cluster or module communication", 1]],
        'diy' => ["Note whether the speedometer works. If ABS/traction lights are also on, a wheel speed sensor is likely."],
        'tests' => ["Compare VSS PID to wheel speeds; scope the sensor signal; check ABS codes."],
        'related' => ['P0720', 'C0035']],
    'voltage_low' => ['sev' => 3, 'drive' => 'careful', 'cost' => [0, 700], 'diff' => 2,
        'plain' => "The computer saw system voltage too low. A weak battery, a failing alternator or a bad cable/ground can also cause a pile of random other codes.",
        'causes' => [["Battery weak or old (3-5 years)", 3], ["Alternator not charging", 2], ["Loose/corroded battery terminal or ground strap", 2], ["Parasitic drain", 1]],
        'diy' => ["Measure battery voltage: about 12.6 V engine off, 13.5-14.7 V running. Below 13 V running = charging problem.", "Clean and tighten both battery terminals and check the engine ground strap.", "Most parts stores test battery and alternator for free."],
        'tests' => ["Load-test the battery; charging output and ripple test; voltage drop on positive and ground circuits under load."],
        'related' => ['P0563', 'U0100']],
    'voltage_high' => ['sev' => 3, 'drive' => 'careful', 'cost' => [80, 700], 'diff' => 2,
        'plain' => "System voltage too high: usually the alternator's regulator is overcharging. This can damage the battery and electronics.",
        'causes' => [["Alternator voltage regulator failed", 3], ["Poor sense circuit or ground", 2], ["Jump-start or charger connected while driving", 1]],
        'diy' => ["Measure voltage at the battery with engine running; above ~15 V is overcharging."],
        'tests' => ["Check charging output, regulator control (LIN/PWM on newer cars) and grounds."],
        'related' => ['P0562']],
    'charging' => ['sev' => 3, 'drive' => 'careful', 'cost' => [150, 800], 'diff' => 3,
        'plain' => "The computer sees a problem with the alternator (generator) control or output. The battery may not be charging.",
        'causes' => [["Alternator failed", 3], ["Wiring/connector to the alternator", 2], ["Blown charging fuse", 1]],
        'diy' => ["Check running voltage at the battery (13.5-14.7 V is normal)."],
        'tests' => ["Check regulator command/feedback, output under load, and fuse/fusible link."],
        'related' => ['P0562']],
    'ecu_internal' => ['sev' => 3, 'drive' => 'careful', 'cost' => [0, 1500], 'diff' => 4,
        'plain' => "The engine computer reports an internal or programming problem. Before blaming the computer, rule out low battery voltage, bad grounds and water damage; sometimes a software update fixes it.",
        'causes' => [["Low/unstable supply voltage or bad ground to the module", 3], ["Software corruption; needs reflash or update", 2], ["Module failed (water, heat)", 2]],
        'diy' => ["Check battery condition and terminals first.", "Ask a dealer or shop whether a software update exists for your car."],
        'tests' => ["Check module powers/grounds with voltage drop; look for TSBs and reflash; replacement modules often need programming."],
        'related' => ['P0562', 'U0100']],
    'tcm_request' => ['sev' => 3, 'drive' => 'careful', 'cost' => [0, 3500], 'diff' => 3,
        'plain' => "The transmission computer has stored its own fault and asked the engine computer to turn on the check-engine light. P0700 itself says nothing about what is wrong: the real code is in the transmission module, which basic $20 readers cannot always see.",
        'causes' => [["Any transmission fault (solenoid, sensor, pressure, ratio)", 3], ["Low or burnt transmission fluid", 2], ["Wiring/connector at the transmission (often leaking fluid into the plug)", 2]],
        'diy' => ["Get the transmission module codes read with a scanner that supports your brand (many parts stores and shops do this).", "Check the fluid level and condition if your transmission has a dipstick."],
        'tests' => ["Read TCM codes and freeze frame; check fluid level/condition; then follow the TCM code."],
        'related' => ['P0715', 'P0730', 'P0750']],
    'trans_sensor' => ['sev' => 3, 'drive' => 'careful', 'cost' => [80, 900], 'diff' => 3,
        'plain' => "A transmission sensor (range/position, temperature or speed) reads wrong or its circuit has a fault. Shifting can become harsh, or the transmission may go into limp mode (stuck in one gear).",
        'causes' => [["Sensor failed", 3], ["Connector or wiring; fluid wicking into connectors", 2], ["Internal harness / conductor plate (many modern automatics)", 2], ["Low fluid", 1]],
        'diy' => ["Check the transmission fluid level and look for fluid in the connector at the transmission."],
        'tests' => ["Compare sensor PIDs with actual (temp vs IR thermometer, speeds vs engine rpm and gear)."],
        'related' => ['P0700', 'P0720', 'P0730']],
    'trans_ratio' => ['sev' => 4, 'drive' => 'careful', 'cost' => [150, 4500], 'diff' => 4,
        'plain' => "The transmission is slipping: the speed going in does not match the speed coming out for the gear it is in. Low fluid is the first thing to check; otherwise internal wear or a valve-body problem.",
        'causes' => [["Low or degraded transmission fluid", 3], ["Worn clutches/bands (internal wear)", 2], ["Shift solenoid or valve body sticking", 2], ["Speed sensor reading wrong", 1]],
        'diy' => ["Check fluid level and smell: dark and burnt-smelling fluid means internal wear.", "Avoid hard acceleration and towing until it is checked; slipping wears the transmission fast."],
        'tests' => ["Line pressure test; check speed sensor signals; adaptation values; inspect pan debris."],
        'related' => ['P0700', 'P0750', 'P0868']],
    'tcc' => ['sev' => 3, 'drive' => 'soon', 'cost' => [100, 2500], 'diff' => 4,
        'plain' => "The torque converter clutch (which locks the engine to the transmission at cruise for fuel economy) is not working right. Symptoms: shudder at 35-50 mph, higher rpm on the highway, worse fuel economy.",
        'causes' => [["Degraded fluid (a fluid change or shudder additive fixes some)", 2], ["TCC solenoid or valve body", 2], ["Torque converter clutch worn", 2], ["Wiring/connector", 1]],
        'diy' => ["Check the fluid and ask about a fluid service with the correct fluid; it fixes some shudder complaints."],
        'tests' => ["Watch TCC slip rpm and command in live data; check solenoid resistance and control; pressure test."],
        'related' => ['P0700', 'P0741']],
    'shift_solenoid' => ['sev' => 3, 'drive' => 'careful', 'cost' => [150, 1500], 'diff' => 3,
        'plain' => "A transmission shift solenoid (a valve the computer uses to change gears) or its circuit has a fault. Shifts may be harsh, late, or the transmission may stay in one gear (limp mode).",
        'causes' => [["Shift solenoid failed or stuck from debris", 3], ["Wiring/connector, internal harness", 2], ["Low or dirty fluid", 2], ["Valve body wear", 1]],
        'diy' => ["Check fluid level/condition. Limp mode often clears after a restart, but the fault will return."],
        'tests' => ["Check solenoid resistance at the transmission connector; command solenoids; inspect internal harness."],
        'related' => ['P0700', 'P0730']],
    'trans_pressure' => ['sev' => 4, 'drive' => 'careful', 'cost' => [150, 3500], 'diff' => 4,
        'plain' => "Transmission hydraulic pressure is wrong, or the pressure switch/sensor reports a fault. Low pressure means slipping and internal wear.",
        'causes' => [["Low fluid", 3], ["Pressure control solenoid / valve body", 2], ["Pump wear or internal leak", 2], ["Pressure switch/sensor", 1]],
        'diy' => ["Check fluid level. Stop towing and hard driving."],
        'tests' => ["Line pressure gauge test vs spec; check pressure control solenoid current."],
        'related' => ['P0700', 'P0730']],
    'cam_actuator_circuit' => ['sev' => 3, 'drive' => 'soon', 'cost' => [60, 500], 'diff' => 2,
        'plain' => "The electrical circuit of a variable valve timing solenoid (cam adjuster) has a fault. The engine may idle rough or lose power.",
        'causes' => [["VVT/oil control solenoid failed", 3], ["Connector or wiring; oil leaking into the connector", 2], ["Computer driver (rare)", 1]],
        'diy' => ["Unplug the solenoid connector and look for oil inside it (very common on some engines).", "These solenoids are often bolted on the front of the cylinder head and are a reasonable DIY."],
        'tests' => ["Solenoid resistance (often 6-12 ohm); command duty cycle and watch cam position respond."],
        'related' => ['P0011', 'P0016']],
    'cam_timing' => ['sev' => 3, 'drive' => 'soon', 'cost' => [0, 2500], 'diff' => 3,
        'plain' => "The camshaft is not at the timing the computer commanded. Very often low or old engine oil (the adjusters run on oil pressure), a sticking oil control solenoid, or a stretched timing chain.",
        'causes' => [["Low oil level, wrong viscosity or overdue oil change", 3], ["Oil control (VVT) solenoid sticking or screen clogged", 3], ["Cam phaser worn", 2], ["Timing chain stretched / tensioner weak", 2]],
        'diy' => ["Check the oil level right away and change the oil (correct grade) if it is overdue; clear the code and see if it returns.", "Rattle for a second or two at cold start points at phasers or chain tensioner."],
        'tests' => ["Command cam advance and compare desired vs actual cam angle; check solenoid screen; check cam/crank correlation for chain stretch."],
        'related' => ['P0016', 'P0010', 'P0300']],
    'cam_crank_corr' => ['sev' => 3, 'drive' => 'careful', 'cost' => [80, 3500], 'diff' => 4,
        'plain' => "The positions of the crankshaft and camshaft do not line up as they should. Often a stretched timing chain or worn sprocket; sometimes just a sensor or low oil. A chain that jumps can wreck the engine, so do not ignore it.",
        'causes' => [["Timing chain stretched, tensioner or guides worn", 3], ["Cam phaser stuck / oil problem", 2], ["Cam or crank sensor / reluctor wheel", 2], ["Belt jumped a tooth (belt engines)", 1]],
        'diy' => ["Check oil level and condition first.", "Listen for chain rattle at cold start. If present, get it inspected soon."],
        'tests' => ["Read cam/crank correlation (deviation) values where the scan tool supports it; scope cam and crank signals together; inspect chain/guides."],
        'related' => ['P0011', 'P0340', 'P0335']],
    'ckp_sensor' => ['sev' => 4, 'drive' => 'careful', 'cost' => [60, 450], 'diff' => 2,
        'plain' => "The crankshaft position sensor signal is missing or wrong. This sensor tells the computer engine speed; when it fails the engine can stall and not restart, often when hot.",
        'causes' => [["Crank sensor failed (heat)", 3], ["Wiring/connector", 2], ["Damaged reluctor (tone) ring", 1]],
        'diy' => ["If the car stalls when hot and restarts after cooling down, this sensor is a top suspect.", "Check the connector; the sensor is usually one bolt on the block or bell housing."],
        'tests' => ["Check RPM signal while cranking; scope the signal; check 5 V/12 V supply and ground."],
        'related' => ['P0340', 'P0016']],
    'cmp_sensor' => ['sev' => 3, 'drive' => 'careful', 'cost' => [50, 350], 'diff' => 2,
        'plain' => "A camshaft position sensor signal is missing or wrong. The engine may be slow to start, run rough, or lose power.",
        'causes' => [["Cam sensor failed", 3], ["Wiring/connector (oil in the plug)", 2], ["Timing chain/phaser problem", 1]],
        'diy' => ["Check the sensor connector and wiring; the sensor is usually one bolt on the head or timing cover."],
        'tests' => ["Scope cam vs crank signals; check supply and ground; check for timing codes too."],
        'related' => ['P0335', 'P0016']],
    'knock_sensor' => ['sev' => 2, 'drive' => 'soon', 'cost' => [80, 900], 'diff' => 3,
        'plain' => "The knock sensor or its circuit has a fault. The computer then pulls ignition timing to protect the engine, which costs power and fuel economy.",
        'causes' => [["Knock sensor or its harness failed (often under the intake manifold)", 3], ["Connector corrosion", 2], ["Real engine knock / mechanical noise", 1]],
        'diy' => ["Use the fuel octane the manual asks for. Listen for knocking/pinging under load."],
        'tests' => ["Check sensor resistance/voltage signal; tap test near the sensor with live data; inspect harness."],
        'related' => ['P0300']],
    'maf' => ['sev' => 3, 'drive' => 'soon', 'cost' => [0, 400], 'diff' => 1,
        'plain' => "The mass airflow (MAF) sensor reading is out of range or does not match what the computer expects. A dirty MAF is very common and cheap to clean.",
        'causes' => [["MAF sensor dirty or failed", 3], ["Air leak between MAF and throttle", 2], ["Clogged or oily air filter", 2], ["Wiring/connector", 1]],
        'diy' => ["Clean the MAF with MAF-cleaner spray only and let it dry; replace a dirty air filter; avoid oiled filters.", "Check the intake boot after the MAF for cracks."],
        'tests' => ["Warm idle MAF g/s ~ engine litres; snap-throttle peak; compare to calculated airflow; check 5 V/12 V supply and ground."],
        'related' => ['P0171', 'P0174']],
    'map' => ['sev' => 3, 'drive' => 'soon', 'cost' => [40, 300], 'diff' => 2,
        'plain' => "The manifold pressure (MAP) or boost sensor reading is out of range or does not match. It can cause poor running, rich/lean mixture or limp mode on turbo engines.",
        'causes' => [["MAP sensor failed or its vacuum port clogged", 3], ["Vacuum leak or cracked hose to the sensor", 2], ["Wiring/5 V reference", 1]],
        'diy' => ["Check the sensor's hose (if it has one) for cracks and that the port is clear."],
        'tests' => ["Key-on engine-off MAP should equal barometric pressure; warm idle typically 25-35 kPa (higher = low vacuum)."],
        'related' => ['P0171', 'P0299']],
    'temp_sensor' => ['sev' => 2, 'drive' => 'soon', 'cost' => [30, 250], 'diff' => 2,
        'plain' => "A temperature sensor (intake air or coolant) reads out of range or its circuit has a fault. A bad coolant sensor can cause hard starting, poor economy, or the cooling fan running all the time.",
        'causes' => [["Sensor failed", 3], ["Connector/wiring", 2], ["Real temperature problem (thermostat)", 1]],
        'diy' => ["Compare the reading in a scan app with the real temperature (cold engine: should match outside air)."],
        'tests' => ["Check sensor resistance vs temperature chart; open/short tests on the circuit."],
        'related' => ['P0128', 'P0125']],
    'thermostat' => ['sev' => 2, 'drive' => 'soon', 'cost' => [80, 500], 'diff' => 2,
        'plain' => "The engine takes too long to warm up, usually a thermostat stuck open. You may notice weak cabin heat and a temperature gauge that stays low.",
        'causes' => [["Thermostat stuck open", 3], ["Coolant temperature sensor reading low", 2], ["Low coolant level", 1], ["Thermostat heater circuit (some engines)", 1]],
        'diy' => ["Check the coolant level (engine cold).", "If cabin heat is weak and the gauge stays low on the highway, the thermostat is the likely fix."],
        'tests' => ["Graph ECT during warm-up: should reach ~85-95 C within 10-15 minutes of driving; IR-check the upper hose."],
        'related' => ['P0125', 'P0117']],
    'overheat' => ['sev' => 5, 'drive' => 'stop', 'cost' => [80, 3000], 'diff' => 3,
        'plain' => "The engine got too hot. Driving an overheating engine can warp the cylinder head and blow the head gasket. Stop, let it cool, check coolant.",
        'causes' => [["Low coolant from a leak", 3], ["Cooling fan not running", 2], ["Thermostat stuck closed", 2], ["Water pump failed", 2], ["Head gasket failure", 1]],
        'diy' => ["Never open a hot radiator cap. Check coolant level when cold and look for leaks (sweet smell, puddles).", "With the engine warm and A/C on, the cooling fan(s) should run."],
        'tests' => ["Pressure-test the cooling system; combustion leak (block) test; verify fan operation and thermostat."],
        'related' => ['P0480', 'P0128']],
    'fan_circuit' => ['sev' => 3, 'drive' => 'careful', 'cost' => [60, 800], 'diff' => 2,
        'plain' => "The cooling fan control circuit has a fault. If the fan does not run, the engine can overheat in traffic and the A/C stops cooling at idle.",
        'causes' => [["Fan motor or fan control module failed", 3], ["Fan relay or fuse", 2], ["Wiring/connector", 2]],
        'diy' => ["Watch the temperature gauge in traffic. Check the fan fuses and relays (swap an identical relay to test)."],
        'tests' => ["Command the fan with a scan tool; check power, ground and control signal at the fan."],
        'related' => ['P0217']],
    'throttle' => ['sev' => 4, 'drive' => 'careful', 'cost' => [0, 700], 'diff' => 2,
        'plain' => "The electronic throttle or its sensors disagree or do not respond as commanded. The car often goes into reduced-power (limp) mode.",
        'causes' => [["Dirty throttle body (carbon)", 3], ["Throttle body motor/sensor failed", 2], ["Accelerator pedal sensor", 1], ["Wiring/connector, 5 V reference", 2]],
        'diy' => ["Clean the throttle body bore and plate (engine off, key off; do not force the plate on electronic throttles), then do a throttle relearn."],
        'tests' => ["Compare TPS 1/2 and APP 1/2 voltages through the sweep; check 5 V reference and grounds; check motor current."],
        'related' => ['P2135', 'P0121', 'P2111']],
    'tps' => ['sev' => 3, 'drive' => 'careful', 'cost' => [40, 600], 'diff' => 2,
        'plain' => "A throttle or pedal position sensor reads out of range. The car may hesitate, idle poorly, or go into reduced power.",
        'causes' => [["Sensor worn or failed (often integrated in the throttle body/pedal)", 3], ["Wiring/connector", 2], ["5 V reference shorted by another sensor", 1]],
        'diy' => ["Check the connector at the throttle body and pedal."],
        'tests' => ["Sweep the sensor and watch for dropouts; check 5 V reference and ground."],
        'related' => ['P2135', 'P0122']],
    'boost_low' => ['sev' => 3, 'drive' => 'careful', 'cost' => [20, 2500], 'diff' => 3,
        'plain' => "The turbocharger is not making the boost the computer asked for. Usually a boost leak (split hose or loose clamp), a sticking wastegate, or a worn turbo.",
        'causes' => [["Boost leak: split intercooler hose, loose clamp, cracked intercooler", 3], ["Wastegate/actuator sticking or vacuum line", 2], ["Diverter/bypass valve leaking", 2], ["Turbo worn or damaged", 1], ["Restricted intake or exhaust", 1]],
        'diy' => ["Inspect all intercooler hoses and clamps for oil-wet splits; listen for a whoosh under boost."],
        'tests' => ["Boost leak test (pressurise intake); compare desired vs actual boost; check wastegate actuator movement and turbo shaft play."],
        'related' => ['P0234', 'P0236']],
    'boost_high' => ['sev' => 4, 'drive' => 'careful', 'cost' => [80, 1500], 'diff' => 3,
        'plain' => "The engine made more boost than allowed. The computer cuts power to protect the engine. Usually the wastegate is stuck closed or its control is faulty.",
        'causes' => [["Wastegate stuck closed / actuator", 3], ["Boost control solenoid or vacuum lines", 2], ["Boost sensor reading wrong", 1]],
        'diy' => ["Avoid full throttle until checked."],
        'tests' => ["Check wastegate movement, control solenoid operation, and boost sensor vs barometric at key-on."],
        'related' => ['P0299']],
    'fuel_pressure_low' => ['sev' => 4, 'drive' => 'careful', 'cost' => [100, 2000], 'diff' => 3,
        'plain' => "Fuel pressure is lower than commanded. Expect hesitation, loss of power, and possibly stalling or no-start. On direct-injection engines the high-pressure fuel pump is a common cause.",
        'causes' => [["Fuel pump weak (in-tank) or high-pressure pump worn (direct injection)", 3], ["Clogged fuel filter", 2], ["Pressure sensor or regulator", 2], ["Wiring/relay to the pump", 1]],
        'diy' => ["Make sure you are not running on a nearly empty tank; note if it gets worse when hot or low on fuel."],
        'tests' => ["Compare desired vs actual rail pressure under load; mechanical gauge on low-pressure side; check pump voltage drop."],
        'related' => ['P0171', 'P0300']],
    'fuel_pressure_high' => ['sev' => 3, 'drive' => 'careful', 'cost' => [100, 1200], 'diff' => 3,
        'plain' => "Fuel pressure is higher than commanded. Often a pressure regulator or the rail pressure sensor.",
        'causes' => [["Pressure regulator / control valve", 3], ["Rail pressure sensor reading wrong", 2], ["Return line restriction", 1]],
        'diy' => ["Mostly shop work; note any fuel smell."],
        'tests' => ["Compare sensor reading with a gauge; check regulator control."],
        'related' => ['P0172']],
    'fuel_pressure_sensor' => ['sev' => 3, 'drive' => 'careful', 'cost' => [80, 600], 'diff' => 3,
        'plain' => "The fuel rail pressure sensor reads out of range or its circuit has a fault.",
        'causes' => [["Sensor failed", 3], ["Wiring/5 V reference", 2], ["Real pressure problem", 1]],
        'diy' => ["Check the connector on the fuel rail."],
        'tests' => ["Key-on engine-off reading vs gauge; check reference and ground."],
        'related' => ['P0087']],
    'fuel_pump_circuit' => ['sev' => 4, 'drive' => 'careful', 'cost' => [80, 1100], 'diff' => 3,
        'plain' => "The fuel pump control circuit has a fault. The car may stall or not start.",
        'causes' => [["Fuel pump relay/fuse or control module", 3], ["Wiring/connector at the tank", 2], ["Fuel pump drawing too much current", 2]],
        'diy' => ["Listen for the pump humming for 2 seconds at key-on. Check the pump fuse and relay."],
        'tests' => ["Check pump power, ground and control; current-ramp the pump."],
        'related' => ['P0087']],
    'imrc' => ['sev' => 2, 'drive' => 'soon', 'cost' => [60, 1500], 'diff' => 3,
        'plain' => "The intake manifold runner or swirl/tumble flap control is stuck or its sensor reads wrong. The engine loses some low-end torque or idle quality. On some engines broken flap linkage is a known failure.",
        'causes' => [["Flap linkage broken or flaps carboned up", 3], ["Runner control actuator/vacuum solenoid", 2], ["Position sensor", 2], ["Cracked manifold or vacuum leak", 1]],
        'diy' => ["Look for a disconnected or broken rod/linkage on the intake manifold (common on several European engines)."],
        'tests' => ["Command flaps and watch actuator movement and position sensor; check vacuum supply."],
        'related' => ['P0171', 'P2187']],
    'idle_lean_rich' => ['sev' => 2, 'drive' => 'soon', 'cost' => [20, 600], 'diff' => 2,
        'plain' => "The mixture is off only at idle. Lean at idle is almost always a vacuum leak (a small leak matters most at idle); rich at idle points at purge or injectors.",
        'causes' => [["Vacuum leak (lean) — PCV, intake gasket, hoses", 3], ["Purge valve leaking (rich)", 2], ["Leaking injector (rich)", 1]],
        'diy' => ["Listen for a hiss at idle and check PCV hoses."],
        'tests' => ["Smoke test; pinch the purge line at idle and watch trims."],
        'related' => ['P0171', 'P0507']],
    'oil_pressure' => ['sev' => 5, 'drive' => 'stop', 'cost' => [20, 4000], 'diff' => 3,
        'plain' => "Oil pressure is low or the oil pressure sensor/switch circuit has a fault. Real low oil pressure destroys an engine in minutes, so check the oil level before driving further.",
        'causes' => [["Low oil level", 3], ["Oil pressure sensor/switch failed or leaking", 3], ["Wiring", 2], ["Worn oil pump or bearings, clogged pickup", 1]],
        'diy' => ["Check the dipstick now. If the level is fine and there are no noises, a failed sensor is likely, but verify with a mechanical gauge before trusting it."],
        'tests' => ["Mechanical oil pressure gauge test at idle and 2,500 rpm vs spec; check sensor circuit."],
        'related' => []],
    'immobilizer' => ['sev' => 3, 'drive' => 'ok', 'cost' => [0, 600], 'diff' => 3,
        'plain' => "The anti-theft (immobilizer) system did not recognise the key or has a fault. The car may crank but not start.",
        'causes' => [["Key transponder not recognised (weak key battery, damaged key, unprogrammed key)", 3], ["Antenna ring around the ignition / start button", 2], ["Module communication or low battery voltage", 2]],
        'diy' => ["Try the spare key. Replace the key fob battery. Keep other keys/fobs away from the ignition."],
        'tests' => ["Read immobilizer module codes and key status with a brand-capable tool; check antenna and supply."],
        'related' => []],
    'readiness' => ['sev' => 1, 'drive' => 'ok', 'cost' => [0, 0], 'diff' => 1,
        'plain' => "Not a fault: the emission self-tests (readiness monitors) have not finished since the codes were cleared or the battery was disconnected. Drive normally for a few days (mixed city and highway) and they complete.",
        'causes' => [["Codes recently cleared or battery disconnected", 3]],
        'diy' => ["Drive normally for 50-100 miles over several days, including a highway stretch and an overnight cold start.", "Buying a used car? Monitors not ready can mean someone cleared codes just before the sale."],
        'tests' => ["Check Mode 1 monitor status; follow the manufacturer drive cycle."],
        'related' => []],
    'info' => ['sev' => 1, 'drive' => 'ok', 'cost' => [0, 0], 'diff' => 1,
        'plain' => "An informational code, usually stored after a battery disconnect or a module reset.",
        'causes' => [["Battery was disconnected or the module was reset", 3]],
        'diy' => ["Clear it; if it comes back on its own, check battery condition and terminals."],
        'tests' => ["Check battery and module supply history."],
        'related' => []],
    'can_bus' => ['sev' => 3, 'drive' => 'careful', 'cost' => [0, 1500], 'diff' => 4,
        'plain' => "The control modules lost communication with each other (or one stopped talking). If several modules are listed, the cause is usually power, ground or the network wiring, not every module at once.",
        'causes' => [["Low battery voltage or poor ground", 3], ["Module listed lost power (fuse) or failed", 2], ["Network (CAN) wiring damaged, water in a connector", 2], ["Aftermarket device on the OBD port or wiring (remote start, tracker)", 1]],
        'diy' => ["Check battery condition and terminals. Unplug any aftermarket OBD dongle and see if the codes stop.", "Note which warning lights are on: they hint at the module that dropped out."],
        'tests' => ["Check the named module's power and ground fuses; measure CAN high/low (~2.5 V each at rest, 60 ohm across the bus with battery off); unplug modules one by one to find a bus-dragging module."],
        'related' => ['P0562']],
    'can_data' => ['sev' => 2, 'drive' => 'soon', 'cost' => [0, 1000], 'diff' => 4,
        'plain' => "A module received data from another module that does not make sense (invalid data). Usually the sending module has its own fault code, or software mismatch after a repair.",
        'causes' => [["Fault in the sending module (check its codes)", 3], ["Software/coding mismatch after a module replacement", 2], ["Network wiring", 1]],
        'diy' => ["Have all modules scanned; fix the root code in the module named."],
        'tests' => ["Full-system scan; check for TSBs/software updates; verify module coding."],
        'related' => []],
    'abs_wheel_speed' => ['sev' => 3, 'drive' => 'soon', 'cost' => [50, 450], 'diff' => 2,
        'plain' => "An ABS wheel speed sensor or its signal has a fault. ABS and traction control are switched off (normal brakes still work), and some cars also lose the speedometer or cruise control.",
        'causes' => [["Wheel speed sensor failed or covered in metal debris", 3], ["Wiring damaged at the wheel (flexes with suspension)", 2], ["Damaged tone ring or worn wheel bearing", 2]],
        'diy' => ["Check the sensor wiring at the named wheel for breaks; the sensor is usually one bolt and a common DIY."],
        'tests' => ["Compare all four wheel speed PIDs while driving; check sensor resistance or signal (AC or digital); inspect tone ring."],
        'related' => ['P0500']],
    'srs' => ['sev' => 4, 'drive' => 'soon', 'cost' => [50, 1200], 'diff' => 4,
        'plain' => "The airbag (SRS) system found a fault and may be switched off: airbags might not deploy in a crash. Do not probe airbag wiring yourself.",
        'causes' => [["Seat connector under a front seat loose (very common)", 3], ["Clock spring in the steering column", 2], ["Seat belt buckle or occupant sensor", 2], ["Airbag module or crash data stored after a collision", 1]],
        'diy' => ["Never probe airbag circuits with a test light or meter. Check for a recall on your VIN (many airbag recalls are open)."],
        'tests' => ["Read SRS module codes with a capable tool; follow the manufacturer's resistance checks with dummy loads only."],
        'related' => []],
    'manufacturer' => ['sev' => 2, 'drive' => 'soon', 'cost' => [0, 1500], 'diff' => 3,
        'plain' => "This is a manufacturer-specific code: its exact meaning depends on the brand. Look it up for your make (links below) and read it with a brand-capable scanner for the full description.",
        'causes' => [["Varies by manufacturer", 2]],
        'diy' => ["Search the code with your make and model; owner forums usually know the common fix."],
        'tests' => ["Use the manufacturer service information (or a brand-capable scan tool) for the exact definition and test plan."],
        'related' => []],
    ];
    return $kb;
}

/**
 * Every code the library knows by number: code => [title, kb family]. Built from the SAE J2012 numbering patterns,
 * so whole families (cylinders, banks, sensors) are covered without listing each code by hand.
 */
function obd_codes_map(): array {
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    $hex = fn(int $n) => sprintf('%03d', $n);
    // Fuel trim, misfire.
    $m['P0170'] = ['Fuel trim malfunction (bank 1)', 'trim_system']; $m['P0171'] = ['System too lean (bank 1)', 'lean']; $m['P0172'] = ['System too rich (bank 1)', 'rich'];
    $m['P0173'] = ['Fuel trim malfunction (bank 2)', 'trim_system']; $m['P0174'] = ['System too lean (bank 2)', 'lean']; $m['P0175'] = ['System too rich (bank 2)', 'rich'];
    $m['P0300'] = ['Random/multiple cylinder misfire detected', 'misfire_random'];
    for ($c = 1; $c <= 12; $c++) {
        $m['P03' . sprintf('%02d', $c)] = ["Cylinder $c misfire detected", 'misfire_cyl'];
        $m['P02' . sprintf('%02d', $c)] = ["Injector circuit / open, cylinder $c", 'injector_circuit'];
        $m['P03' . (50 + $c)] = ["Ignition coil $c (cylinder $c) primary/secondary circuit", 'coil_circuit'];
        $base = 261 + 3 * ($c - 1);
        $m['P0' . $base] = ["Cylinder $c injector circuit low", 'injector_circuit'];
        $m['P0' . ($base + 1)] = ["Cylinder $c injector circuit high", 'injector_circuit'];
        $m['P0' . ($base + 2)] = ["Cylinder $c contribution/balance fault", 'injector_balance'];
    }
    $m['P0200'] = ['Injector circuit malfunction', 'injector_circuit']; $m['P0350'] = ['Ignition coil primary/secondary circuit malfunction', 'coil_circuit'];
    // Oxygen sensors: circuit, range, low, high, no activity, heater per bank/sensor.
    $o2 = [130 => [1, 1], 136 => [1, 2], 142 => [1, 3], 150 => [2, 1], 156 => [2, 2], 162 => [2, 3]];
    $sfx = [0 => ['circuit malfunction', 'o2_circuit'], 1 => ['circuit slow response / range', 'o2_perf'], 2 => ['circuit low voltage', 'o2_low'], 3 => ['circuit high voltage', 'o2_high'], 4 => ['no activity detected', 'o2_noact'], 5 => ['heater circuit malfunction', 'o2_heater']];
    foreach ($o2 as $b => [$bank, $sen]) foreach ($sfx as $i => [$t, $k]) {
        $code = 'P0' . ($b + $i);
        $m[$code] = ["O2 sensor $t (bank $bank, sensor $sen" . ($sen === 1 ? ', upstream' : ', downstream') . ')', $k];
    }
    $m['P0133'][0] = 'O2 sensor circuit slow response (bank 1, sensor 1)'; $m['P0153'][0] = 'O2 sensor circuit slow response (bank 2, sensor 1)';
    $m['P0131'][1] = 'o2_low'; $m['P0134'][1] = 'o2_noact';
    // P0131/P0132 suffix map is offset by one (circuit, low, high, slow, no activity, heater) per SAE: fix the family.
    foreach ($o2 as $b => [$bank, $sen]) {
        $real = [0 => ['circuit malfunction', 'o2_circuit'], 1 => ['circuit low voltage', 'o2_low'], 2 => ['circuit high voltage', 'o2_high'], 3 => ['circuit slow response', 'o2_perf'], 4 => ['circuit no activity detected', 'o2_noact'], 5 => ['heater circuit malfunction', 'o2_heater']];
        foreach ($real as $i => [$t, $k]) $m['P0' . ($b + $i)] = ["O2 sensor $t (bank $bank, sensor $sen" . ($sen === 1 ? ', upstream' : ', downstream') . ')', $k];
    }
    $heat = [30 => [1, 1], 36 => [1, 2], 42 => [1, 3], 50 => [2, 1], 56 => [2, 2], 62 => [2, 3]];
    foreach ($heat as $b => [$bank, $sen]) foreach (['heater control circuit', 'heater control circuit low', 'heater control circuit high'] as $i => $t)
        $m['P00' . ($b + $i)] = ["O2 sensor $t (bank $bank, sensor $sen)", 'o2_heater'];
    foreach ([2195 => ['stuck lean', 1, 1], 2196 => ['stuck rich', 1, 1], 2197 => ['stuck lean', 2, 1], 2198 => ['stuck rich', 2, 1], 2270 => ['stuck lean', 1, 2], 2271 => ['stuck rich', 1, 2], 2272 => ['stuck lean', 2, 2], 2273 => ['stuck rich', 2, 2]] as $n => [$t, $bank, $sen])
        $m['P' . $n] = ["O2 sensor signal $t (bank $bank, sensor $sen)", $sen === 1 ? 'airfuel_stuck' : 'post_cat_trim'];
    $m['P2A00'] = ['O2 sensor circuit range/performance (bank 1, sensor 1)', 'o2_perf']; $m['P2A03'] = ['O2 sensor circuit range/performance (bank 2, sensor 1)', 'o2_perf'];
    foreach ([2096 => 'too lean (bank 1)', 2097 => 'too rich (bank 1)', 2098 => 'too lean (bank 2)', 2099 => 'too rich (bank 2)'] as $n => $t) $m['P' . $n] = ["Post-catalyst fuel trim system $t", 'post_cat_trim'];
    foreach ([2187 => 'too lean at idle (bank 1)', 2188 => 'too rich at idle (bank 1)', 2189 => 'too lean at idle (bank 2)', 2190 => 'too rich at idle (bank 2)'] as $n => $t) $m['P' . $n] = ["System $t", 'idle_lean_rich'];
    // Catalyst.
    $m['P0420'] = ['Catalyst system efficiency below threshold (bank 1)', 'catalyst']; $m['P0421'] = ['Warm-up catalyst efficiency below threshold (bank 1)', 'catalyst'];
    $m['P0430'] = ['Catalyst system efficiency below threshold (bank 2)', 'catalyst']; $m['P0431'] = ['Warm-up catalyst efficiency below threshold (bank 2)', 'catalyst'];
    // EVAP.
    $evap = ['P0440' => ['Evaporative emission system malfunction', 'evap_large'], 'P0441' => ['EVAP system incorrect purge flow', 'evap_purge'], 'P0442' => ['EVAP system leak detected (small leak)', 'evap_small'],
        'P0443' => ['EVAP purge control valve circuit', 'evap_purge'], 'P0444' => ['EVAP purge control valve circuit open', 'evap_purge'], 'P0445' => ['EVAP purge control valve circuit shorted', 'evap_purge'],
        'P0446' => ['EVAP vent control circuit malfunction', 'evap_vent'], 'P0447' => ['EVAP vent control circuit open', 'evap_vent'], 'P0448' => ['EVAP vent control circuit shorted', 'evap_vent'], 'P0449' => ['EVAP vent valve/solenoid circuit', 'evap_vent'],
        'P0450' => ['EVAP pressure sensor malfunction', 'evap_pressure_sensor'], 'P0451' => ['EVAP pressure sensor range/performance', 'evap_pressure_sensor'], 'P0452' => ['EVAP pressure sensor low input', 'evap_pressure_sensor'], 'P0453' => ['EVAP pressure sensor high input', 'evap_pressure_sensor'],
        'P0455' => ['EVAP system leak detected (large leak / no flow)', 'evap_large'], 'P0456' => ['EVAP system leak detected (very small leak)', 'evap_small'], 'P0457' => ['EVAP leak detected (fuel cap loose/off)', 'evap_large'],
        'P0496' => ['EVAP high purge flow (purge valve leaking)', 'evap_purge'], 'P0497' => ['EVAP low purge flow', 'evap_purge']];
    $m += $evap;
    // EGR, secondary air.
    foreach (['P0400' => 'EGR flow malfunction', 'P0401' => 'EGR flow insufficient', 'P0402' => 'EGR flow excessive', 'P0403' => 'EGR control circuit', 'P0404' => 'EGR control circuit range/performance', 'P0405' => 'EGR sensor A circuit low', 'P0406' => 'EGR sensor A circuit high'] as $c => $t) $m[$c] = [$t, 'egr'];
    foreach (['P0410' => 'Secondary air injection system malfunction', 'P0411' => 'Secondary air injection incorrect flow', 'P0412' => 'Secondary air injection switching valve A circuit', 'P0418' => 'Secondary air injection pump relay A circuit', 'P0491' => 'Secondary air injection insufficient flow (bank 1)', 'P0492' => 'Secondary air injection insufficient flow (bank 2)', 'P2440' => 'Secondary air switching valve stuck open (bank 1)', 'P2441' => 'Secondary air switching valve stuck closed (bank 1)', 'P2442' => 'Secondary air switching valve stuck open (bank 2)', 'P2444' => 'Secondary air pump stuck on (bank 1)'] as $c => $t) $m[$c] = [$t, 'secondary_air'];
    // Idle, speed.
    foreach (['P0505' => 'Idle air control system malfunction', 'P0506' => 'Idle control system rpm lower than expected', 'P0507' => 'Idle control system rpm higher than expected'] as $c => $t) $m[$c] = [$t, 'iac_idle'];
    foreach (['P0500' => 'Vehicle speed sensor A malfunction', 'P0501' => 'Vehicle speed sensor A range/performance', 'P0502' => 'Vehicle speed sensor A circuit low', 'P0503' => 'Vehicle speed sensor A intermittent/erratic'] as $c => $t) $m[$c] = [$t, 'vss'];
    // Voltage, charging, computer.
    $m['P0560'] = ['System voltage malfunction', 'voltage_low']; $m['P0561'] = ['System voltage unstable', 'voltage_low']; $m['P0562'] = ['System voltage low', 'voltage_low']; $m['P0563'] = ['System voltage high', 'voltage_high'];
    foreach (['P0620' => 'Generator (alternator) control circuit', 'P0621' => 'Generator lamp/L terminal control circuit', 'P0622' => 'Generator field/F terminal control circuit'] as $c => $t) $m[$c] = [$t, 'charging'];
    foreach (['P0600' => 'Serial communication link malfunction', 'P0601' => 'Internal control module memory checksum error', 'P0602' => 'Control module programming error', 'P0603' => 'Internal control module keep-alive memory (KAM) error', 'P0604' => 'Internal control module RAM error', 'P0605' => 'Internal control module ROM error', 'P0606' => 'Control module processor fault', 'P0607' => 'Control module performance', 'P0610' => 'Control module vehicle options error'] as $c => $t) $m[$c] = [$t, 'ecu_internal'];
    $m['P0603'][1] = 'ecu_internal';
    // Transmission.
    $m['P0700'] = ['Transmission control system malfunction (TCM has a code)', 'tcm_request'];
    foreach (['P0705' => 'Transmission range sensor circuit (PRNDL input)', 'P0706' => 'Transmission range sensor range/performance', 'P0707' => 'Transmission range sensor circuit low', 'P0708' => 'Transmission range sensor circuit high', 'P0709' => 'Transmission range sensor intermittent',
        'P0710' => 'Transmission fluid temperature sensor circuit', 'P0711' => 'Transmission fluid temperature sensor range/performance', 'P0712' => 'Transmission fluid temperature sensor low', 'P0713' => 'Transmission fluid temperature sensor high', 'P0714' => 'Transmission fluid temperature sensor intermittent',
        'P0715' => 'Input/turbine speed sensor circuit', 'P0716' => 'Input/turbine speed sensor range/performance', 'P0717' => 'Input/turbine speed sensor no signal', 'P0718' => 'Input/turbine speed sensor intermittent',
        'P0720' => 'Output speed sensor circuit', 'P0721' => 'Output speed sensor range/performance', 'P0722' => 'Output speed sensor no signal', 'P0723' => 'Output speed sensor intermittent', 'P0725' => 'Engine speed input circuit'] as $c => $t) $m[$c] = [$t, 'trans_sensor'];
    $m['P0730'] = ['Incorrect gear ratio', 'trans_ratio'];
    for ($g = 1; $g <= 6; $g++) $m['P073' . $g] = ["Gear $g incorrect ratio", 'trans_ratio'];
    $m['P0729'] = ['Gear 6 incorrect ratio', 'trans_ratio'];
    foreach (['P0740' => 'Torque converter clutch circuit malfunction', 'P0741' => 'Torque converter clutch performance or stuck off', 'P0742' => 'Torque converter clutch stuck on', 'P0743' => 'Torque converter clutch circuit electrical', 'P0744' => 'Torque converter clutch circuit intermittent'] as $c => $t) $m[$c] = [$t, 'tcc'];
    foreach (['A' => 750, 'B' => 755, 'C' => 760, 'D' => 765, 'E' => 770] as $L => $b) foreach (['malfunction', 'performance or stuck off', 'stuck on', 'electrical', 'intermittent'] as $i => $t)
        $m['P0' . ($b + $i)] = ["Shift solenoid $L $t", 'shift_solenoid'];
    $m['P0780'] = ['Shift malfunction', 'trans_ratio'];
    foreach (['P0840' => 'Transmission fluid pressure sensor/switch A circuit', 'P0841' => 'Transmission fluid pressure sensor/switch A range/performance', 'P0868' => 'Transmission fluid pressure low', 'P0869' => 'Transmission fluid pressure high'] as $c => $t) $m[$c] = [$t, 'trans_pressure'];
    // Cam timing / VVT, cam & crank sensors, knock.
    foreach ([['10', 'A', 1], ['20', 'A', 2]] as [$b, $cam, $bank]) {
        $base = (int)$b;
        $m['P00' . $base] = ["Camshaft position \"A\" actuator circuit (bank $bank)", 'cam_actuator_circuit'];
        $m['P00' . ($base + 1)] = ["Camshaft position \"A\" timing over-advanced or system performance (bank $bank)", 'cam_timing'];
        $m['P00' . ($base + 2)] = ["Camshaft position \"A\" timing over-retarded (bank $bank)", 'cam_timing'];
        $m['P00' . ($base + 3)] = ["Camshaft position \"B\" actuator circuit (bank $bank)", 'cam_actuator_circuit'];
        $m['P00' . ($base + 4)] = ["Camshaft position \"B\" timing over-advanced or system performance (bank $bank)", 'cam_timing'];
        $m['P00' . ($base + 5)] = ["Camshaft position \"B\" timing over-retarded (bank $bank)", 'cam_timing'];
    }
    foreach (['P0016' => 'Crankshaft/camshaft position correlation (bank 1, sensor A)', 'P0017' => 'Crankshaft/camshaft position correlation (bank 1, sensor B)', 'P0018' => 'Crankshaft/camshaft position correlation (bank 2, sensor A)', 'P0019' => 'Crankshaft/camshaft position correlation (bank 2, sensor B)', 'P0008' => 'Engine position system performance (bank 1)', 'P0009' => 'Engine position system performance (bank 2)'] as $c => $t) $m[$c] = [$t, 'cam_crank_corr'];
    foreach (['P0335' => 'circuit malfunction', 'P0336' => 'circuit range/performance', 'P0337' => 'circuit low', 'P0338' => 'circuit high', 'P0339' => 'circuit intermittent'] as $c => $t) $m[$c] = ["Crankshaft position sensor A $t", 'ckp_sensor'];
    foreach ([340 => ['A', 1], 345 => ['A', 2], 365 => ['B', 1], 390 => ['B', 2]] as $b => [$s, $bank]) foreach (['circuit malfunction', 'circuit range/performance', 'circuit low', 'circuit high', 'circuit intermittent'] as $i => $t)
        $m['P0' . ($b + $i)] = ["Camshaft position sensor \"$s\" $t (bank $bank)", 'cmp_sensor'];
    foreach ([325 => [1, 1], 330 => [2, 2]] as $b => [$s, $bank]) foreach (['circuit malfunction', 'circuit range/performance', 'circuit low', 'circuit high', 'circuit intermittent'] as $i => $t)
        $m['P0' . ($b + $i)] = ["Knock sensor $s $t (bank $bank)", 'knock_sensor'];
    // Sensors with the circuit / range / low / high / intermittent pattern.
    $sens = [100 => ['Mass or volume air flow (MAF) sensor A', 'maf'], 105 => ['Manifold absolute pressure / barometric (MAP) sensor', 'map'], 110 => ['Intake air temperature sensor 1', 'temp_sensor'], 115 => ['Engine coolant temperature sensor 1', 'temp_sensor'], 120 => ['Throttle/pedal position sensor A', 'tps'], 220 => ['Throttle/pedal position sensor B', 'tps'], 225 => ['Throttle/pedal position sensor C', 'tps'], 235 => ['Turbo/supercharger boost sensor A', 'map'], 190 => ['Fuel rail pressure sensor', 'fuel_pressure_sensor']];
    foreach ($sens as $b => [$name, $k]) foreach (['circuit malfunction', 'circuit range/performance', 'circuit low input', 'circuit high input', 'circuit intermittent'] as $i => $t)
        $m['P0' . ($b + $i)] = ["$name $t", $k];
    $m['P0125'] = ['Insufficient coolant temperature for closed-loop fuel control', 'thermostat']; $m['P0126'] = ['Insufficient coolant temperature for stable operation', 'thermostat']; $m['P0128'] = ['Coolant thermostat (coolant temperature below thermostat regulating temperature)', 'thermostat'];
    foreach (['P0597' => 'Thermostat heater control circuit open', 'P0598' => 'Thermostat heater control circuit low', 'P0599' => 'Thermostat heater control circuit high'] as $c => $t) $m[$c] = [$t, 'thermostat'];
    $m['P0217'] = ['Engine coolant over-temperature condition', 'overheat']; $m['P1299'] = ['Engine over-temperature / cylinder head over-temperature protection (several makes)', 'overheat'];
    foreach (['P0480' => 'Cooling fan 1 control circuit', 'P0481' => 'Cooling fan 2 control circuit', 'P0482' => 'Cooling fan 3 control circuit', 'P0483' => 'Cooling fan rationality check', 'P0484' => 'Cooling fan circuit over current', 'P0485' => 'Cooling fan power/ground circuit'] as $c => $t) $m[$c] = [$t, 'fan_circuit'];
    // Throttle by wire, pedal.
    foreach (['P2101' => 'Throttle actuator control motor circuit range/performance', 'P2111' => 'Throttle actuator control system stuck open', 'P2112' => 'Throttle actuator control system stuck closed', 'P2118' => 'Throttle actuator control motor current range/performance', 'P2119' => 'Throttle actuator control throttle body range/performance', 'P2135' => 'Throttle/pedal position sensor A/B voltage correlation', 'P2138' => 'Throttle/pedal position sensor D/E voltage correlation', 'P0638' => 'Throttle actuator control range/performance (bank 1)', 'P0121' => 'Throttle/pedal position sensor A circuit range/performance'] as $c => $t) $m[$c] = [$t, 'throttle'];
    foreach ([2120 => 'D', 2125 => 'E'] as $b => $s) foreach (['circuit', 'circuit range/performance', 'circuit low', 'circuit high', 'circuit intermittent'] as $i => $t) $m['P' . ($b + $i)] = ["Throttle/pedal position sensor $s $t", 'tps'];
    // Boost, fuel pressure.
    $m['P0299'] = ['Turbocharger/supercharger underboost', 'boost_low']; $m['P0234'] = ['Turbocharger/supercharger overboost condition', 'boost_high'];
    $m['P0087'] = ['Fuel rail/system pressure too low', 'fuel_pressure_low']; $m['P0088'] = ['Fuel rail/system pressure too high', 'fuel_pressure_high']; $m['P0089'] = ['Fuel pressure regulator 1 performance', 'fuel_pressure_high']; $m['P0093'] = ['Fuel system large leak detected', 'fuel_pressure_low'];
    $m['P0230'] = ['Fuel pump primary circuit', 'fuel_pump_circuit']; $m['P0627'] = ['Fuel pump control circuit open', 'fuel_pump_circuit']; $m['P0628'] = ['Fuel pump control circuit low', 'fuel_pump_circuit']; $m['P0629'] = ['Fuel pump control circuit high', 'fuel_pump_circuit'];
    // Intake runner / swirl flaps.
    foreach (['P2004' => 'Intake manifold runner control stuck open (bank 1)', 'P2005' => 'Intake manifold runner control stuck open (bank 2)', 'P2006' => 'Intake manifold runner control stuck closed (bank 1)', 'P2007' => 'Intake manifold runner control stuck closed (bank 2)', 'P2008' => 'Intake manifold runner control circuit open (bank 1)', 'P2015' => 'Intake manifold runner position sensor/switch circuit range/performance (bank 1)', 'P2016' => 'Intake manifold runner position sensor circuit low (bank 1)', 'P2017' => 'Intake manifold runner position sensor circuit high (bank 1)', 'P0660' => 'Intake manifold tuning valve control circuit (bank 1)', 'P0661' => 'Intake manifold tuning valve control circuit low (bank 1)', 'P0662' => 'Intake manifold tuning valve control circuit high (bank 1)'] as $c => $t) $m[$c] = [$t, 'imrc'];
    // Oil pressure.
    foreach (['P0520' => 'Engine oil pressure sensor/switch circuit', 'P0521' => 'Engine oil pressure sensor/switch range/performance', 'P0522' => 'Engine oil pressure sensor/switch low voltage', 'P0523' => 'Engine oil pressure sensor/switch high voltage', 'P0524' => 'Engine oil pressure too low'] as $c => $t) $m[$c] = [$t, 'oil_pressure'];
    // Network (U0xxx lost communication), invalid data.
    $mods = ['U0100' => 'ECM/PCM "A"', 'U0101' => 'TCM (transmission)', 'U0102' => 'transfer case control module', 'U0103' => 'gear shift control module', 'U0104' => 'cruise control module', 'U0107' => 'throttle actuator control module', 'U0109' => 'fuel pump control module',
        'U0114' => 'four-wheel-drive clutch control module', 'U0115' => 'ECM/PCM "B"', 'U0121' => 'ABS control module', 'U0122' => 'vehicle dynamics (stability) control module', 'U0123' => 'yaw rate sensor module', 'U0124' => 'lateral acceleration sensor module', 'U0126' => 'steering angle sensor module',
        'U0128' => 'park brake control module', 'U0129' => 'brake system control module', 'U0131' => 'power steering control module', 'U0132' => 'ride level control module', 'U0140' => 'body control module (BCM)', 'U0141' => 'body control module "A"', 'U0142' => 'body control module "B"',
        'U0146' => 'gateway "A"', 'U0151' => 'restraints (airbag) control module', 'U0155' => 'instrument panel cluster', 'U0156' => 'information center "A"', 'U0159' => 'parking assist module', 'U0164' => 'HVAC control module', 'U0167' => 'vehicle immobilizer control module',
        'U0168' => 'vehicle security control module', 'U0184' => 'radio', 'U0198' => 'telematics control module', 'U0199' => 'door control module "A"', 'U0200' => 'door control module "B"', 'U0214' => 'remote function actuation (keyless) module', 'U0235' => 'cruise control front distance sensor'];
    foreach ($mods as $c => $t) $m[$c] = ["Lost communication with $t", 'can_bus'];
    $m['U0001'] = ['High-speed CAN communication bus', 'can_bus']; $m['U0073'] = ['Control module communication bus "A" off', 'can_bus']; $m['U0074'] = ['Control module communication bus "B" off', 'can_bus'];
    foreach (['U0401' => 'ECM/PCM', 'U0402' => 'TCM', 'U0415' => 'ABS control module', 'U0416' => 'vehicle dynamics control module', 'U0422' => 'body control module', 'U0428' => 'steering angle sensor module'] as $c => $t) $m[$c] = ["Invalid data received from $t", 'can_data'];
    // Chassis/body generics seen everywhere.
    foreach (['C0035' => 'Left front wheel speed sensor circuit', 'C0040' => 'Right front wheel speed sensor circuit', 'C0045' => 'Left rear wheel speed sensor circuit', 'C0050' => 'Right rear wheel speed sensor circuit'] as $c => $t) $m[$c] = [$t, 'abs_wheel_speed'];
    foreach (['B0001' => 'Driver frontal stage 1 deployment control', 'B0002' => 'Driver frontal stage 2 deployment control', 'B0010' => 'Passenger frontal stage 1 deployment control', 'B0012' => 'Passenger frontal stage 2 deployment control', 'B0020' => 'Left side airbag deployment control', 'B0028' => 'Right side airbag deployment control', 'B0081' => 'Passenger occupant classification system', 'B0092' => 'Left side restraints sensor 2'] as $c => $t) $m[$c] = [$t . ' (airbag system)', 'srs'];
    // Common manufacturer-specific codes (definitions for the named makes only).
    $m['P1000'] = ['OBD readiness tests not complete (Ford, Mazda, Jaguar, Land Rover)', 'readiness'];
    $m['P1131'] = ['Lack of upstream O2 sensor switch, sensor indicates lean (bank 1) (Ford)', 'lean'];
    $m['P1151'] = ['Lack of upstream O2 sensor switch, sensor indicates lean (bank 2) (Ford)', 'lean'];
    $m['P1450'] = ['Unable to bleed up fuel tank vacuum (Ford EVAP)', 'evap_vent'];
    $m['P1456'] = ['EVAP emission control system leak, fuel tank side (Honda/Acura)', 'evap_small'];
    $m['P1457'] = ['EVAP emission control system leak, canister side (Honda/Acura)', 'evap_vent'];
    $m['P1259'] = ['VTEC system malfunction (Honda/Acura)', 'cam_timing'];
    $m['P1349'] = ['VVT system malfunction (Toyota/Lexus, older models)', 'cam_timing'];
    $m['P1684'] = ['Battery power disconnected within the last 50 starts (Chrysler/Dodge/Jeep)', 'info'];
    $m['P1326'] = ['Knock sensor detection system (KSDS): engine bearing wear detected (Hyundai/Kia)', 'knock_sensor'];
    $m['P17F0'] = ['CVT judder (Nissan/Infiniti)', 'tcc'];
    $m['P17F1'] = ['CVT belt slip (Nissan/Infiniti)', 'trans_ratio'];
    return $m;
}

/** Plain explanation of any code from its structure (SAE J2012), used when the library has no entry. */
function obd_structure(string $code): array {
    $sys = ['P' => 'Powertrain (engine, transmission, emissions)', 'B' => 'Body (airbags, seats, lights, climate)', 'C' => 'Chassis (ABS, steering, suspension)', 'U' => 'Network (communication between modules)'][$code[0]];
    $d1 = $code[1];
    $generic = match ($code[0]) { 'P' => in_array($d1, ['0', '2'], true) || ($d1 === '3' && $code[2] >= '4'), default => $d1 === '0' || $d1 === '3' };
    $area = '';
    if ($code[0] === 'P' && str_starts_with($code, 'P34')) $area = 'Cylinder deactivation (variable displacement)';
    elseif ($code[0] === 'P') {
        $area = match ($code[2]) {
            '0' => 'Fuel and air metering, auxiliary emission controls', '1' => 'Fuel and air metering', '2' => 'Fuel and air metering (injector circuits)',
            '3' => 'Ignition system or misfire', '4' => 'Auxiliary emission controls (EGR, EVAP, catalyst, secondary air)', '5' => 'Vehicle speed, idle control and auxiliary inputs',
            '6' => 'Computer and output circuits', '7', '8', '9' => 'Transmission', 'A', 'B', 'C' => 'Hybrid / electric propulsion', default => '',
        };
    }
    return ['system' => $sys, 'generic' => $generic, 'area' => $area];
}
function obd_norm(string $c): ?string {
    $c = strtoupper(trim($c));
    // Common OCR / typing slips: letter O for zero after the system letter.
    if (preg_match('/^[PBCU][0-3O][0-9A-FO]{3}$/', $c)) $c = $c[0] . str_replace('O', '0', substr($c, 1));
    return preg_match('/^[PBCU][0-3][0-9A-F]{3}$/D', $c) ? $c : null;
}
/** Every DTC-looking token in free text (scan reports, pasted lists). Keeps order, no duplicates. */
function obd_extract_codes(string $text): array {
    preg_match_all('/(?<![A-Z0-9])([PBCU][0-3][0-9A-F]{3})(?:-?[0-9A-F]{2})?(?![0-9A-Z])/i', strtoupper($text), $mm);
    return array_values(array_unique(array_filter(array_map('obd_norm', $mm[1]))));
}

/**
 * Well-known patterns for specific vehicles (widely documented failures, TSB/extended-warranty topics).
 * make/model are regexes on the decoded or entered names; years inclusive. Shown as "known pattern", not a verdict.
 */
function obd_vehicle_patterns(): array {
    return [
        ['make' => '/mercedes/i', 'years' => [2005, 2008], 'codes' => ['P0016', 'P0017', 'P0018', 'P0019'],
         'note' => "M272 V6 / M273 V8 (2005-2008): cam/crank correlation codes are famously the balance-shaft sprocket (V6) or idler-gear sprocket (V8) wearing. Mercedes changed the part around engine serial 2729..30 468993 (V6) / 2739..30 088611 (V8); check the engine number. A big job; get a correlation-value reading with XENTRY/STAR before deciding."],
        ['make' => '/mercedes/i', 'years' => [2005, 2014], 'codes' => ['P0010', 'P0011', 'P0013', 'P0014', 'P0020', 'P0021', 'P0023', 'P0024'],
         'note' => "M272/M273 and related engines: the cam adjuster magnets (solenoids) on the front of the heads leak oil into their connectors and the oil can wick down the harness toward the engine computer. Unplug the magnets and look for oil; replace the magnets and clean or replace the harness connector if oil is present."],
        ['make' => '/mercedes/i', 'years' => [2005, 2011], 'codes' => ['P2004', 'P2005', 'P2006', 'P2007', 'P2187', 'P2189', 'P0171', 'P0174'],
         'note' => "M272 V6: the intake manifold's tumble/swirl flaps and their plastic linkage break, and the manifold can crack, causing runner-control codes and lean codes on both banks. A replacement manifold (e.g. Pierburg) is the usual fix."],
        ['make' => '/mercedes/i', 'years' => [2004, 2016], 'codes' => ['P0700', 'P0715', 'P0717', 'P0720', 'P0722', 'P0730'],
         'note' => "722.9 7G-Tronic automatic: the conductor plate (internal speed sensors on the valve body) is a known failure, causing limp mode (stuck in 2nd/3rd) with speed-sensor codes. Also check for fluid wicking through the 13-pin connector and its adapter plug."],
        ['make' => '/ford|lincoln/i', 'model' => '/f-?150|expedition|navigator|mark lt/i', 'years' => [2004, 2010], 'codes' => ['P0011', 'P0012', 'P0021', 'P0022', 'P0016', 'P0018', 'P0300'],
         'note' => "5.4L 3-valve V8: cam phasers, VCT solenoids and timing-chain tensioners wear (ticking/knocking at warm idle). Start with oil level/grade (5W-20) and the VCT solenoids; persistent codes usually mean phasers, chain, guides and tensioners."],
        ['make' => '/ford/i', 'model' => '/f-?150|expedition|explorer|taurus|flex|transit/i', 'years' => [2011, 2016], 'codes' => ['P0299', 'P0300', 'P0301', 'P0302', 'P0303', 'P0304', 'P0305', 'P0306'],
         'note' => "3.5L EcoBoost: misfires or stumbling under hard acceleration in humid weather are a known charge-air-cooler condensation issue (Ford released an intercooler deflector/updated part). Also check for boost leaks at the intercooler hoses."],
        ['make' => '/chevrolet|gmc|cadillac/i', 'model' => '/silverado|sierra|tahoe|yukon|suburban|avalanche|escalade/i', 'years' => [2007, 2014], 'codes' => ['P0300', 'P0301', 'P0302', 'P0303', 'P0304', 'P0305', 'P0306', 'P0307', 'P0308', 'P0521'],
         'note' => "5.3/6.0/6.2 V8 with Active Fuel Management: AFM lifter collapse (ticking + misfire on cylinders 1, 4, 6 or 7) and oil consumption fouling plugs are both common. Check for lifter tick and oil use before replacing coils."],
        ['make' => '/chevrolet/i', 'model' => '/cruze|sonic|trax|encore/i', 'years' => [2011, 2016], 'codes' => ['P0171', 'P0106', 'P0507', 'P2187', 'P0597', 'P0598', 'P0599'],
         'note' => "1.4L turbo: the PCV diaphragm built into the valve cover tears (whistle at idle, lean codes, oil consumption); fix is a new valve cover. P0597-P0599 are the thermostat housing (built-in heater), also a known failure with coolant leaks."],
        ['make' => '/honda|acura/i', 'model' => '/accord|odyssey|pilot|ridgeline|crosstour|mdx|tl/i', 'years' => [2008, 2016], 'codes' => ['P0300', 'P0301', 'P0302', 'P0303', 'P0304', 'P0305', 'P0306'],
         'note' => "3.5L V6 with Variable Cylinder Management (VCM): oil consumption and fouled spark plugs on the cylinders that shut off (often 1-3 or 4-6) are common; Honda extended warranty on some years for piston rings. Check plugs for oil fouling and ask a dealer about software/warranty coverage."],
        ['make' => '/toyota|scion/i', 'model' => '/camry|rav4|tc|solara|highlander/i', 'years' => [2006, 2011], 'codes' => ['P0300', 'P0420', 'P0171'],
         'note' => "2.4L 2AZ-FE: known for oil consumption (piston rings), which fouls plugs and wears the catalytic converter. Check oil level between changes before replacing a converter."],
        ['make' => '/toyota|lexus/i', 'model' => '/tundra|sequoia|land cruiser|lx/i', 'years' => [2007, 2014], 'codes' => ['P2440', 'P2441', 'P2442', 'P2444', 'P0418', 'P0411'],
         'note' => "i-Force V8: the secondary air injection pump and switching valves fail (water intrusion), putting the truck in limp mode. Toyota extended coverage on some years; check with a dealer by VIN."],
        ['make' => '/nissan|infiniti/i', 'model' => '/altima|rogue|sentra|versa|murano|pathfinder|maxima|juke|qx60/i', 'years' => [2007, 2019], 'codes' => ['P17F0', 'P17F1', 'P0744', 'P0746', 'P0776', 'P0778', 'P0841', 'P0868', 'P0717', 'P0720'],
         'note' => "Jatco CVT: judder, whine and slipping are common. Check CVT fluid (correct NS-2/NS-3), ask for the latest TCM software and check whether Nissan's extended CVT warranty covers your VIN."],
        ['make' => '/volkswagen|vw|audi/i', 'years' => [2008, 2016], 'codes' => ['P0171', 'P0507', 'P0106', 'P2187', 'P2015', 'P0016'],
         'note' => "2.0T TSI/TFSI (EA888): the PCV/oil separator valve fails (lean codes, high idle, whistling); P2015 is the intake manifold runner flap linkage (very common, often a new manifold). On 2008-2012 engines P0016 can mean the timing chain tensioner is failing: inspect it urgently, a jumped chain destroys the engine."],
        ['make' => '/bmw/i', 'years' => [1999, 2018], 'codes' => ['P0171', 'P0174', 'P2187', 'P2189'],
         'note' => "BMW inline-6 and V8: lean codes most often come from the crankcase ventilation valve (CCV, in or on the valve cover), cracked intake boots, the DISA valve, or the oil filler cap seal. Smoke-test before buying sensors."],
        ['make' => '/bmw/i', 'model' => '/135|335|535|x5|x6|z4|740|1 series|3 series|5 series/i', 'years' => [2007, 2016], 'codes' => ['P0087', 'P0300', 'P0301', 'P0302', 'P0303', 'P0304', 'P0305', 'P0306', 'P0299'],
         'note' => "N54/N55 turbo inline-6: high-pressure fuel pump failure (P0087, long cranking, limp mode), leaking injectors and worn coils/plugs are classic. Wastegate rattle and boost leaks cause P0299."],
        ['make' => '/subaru/i', 'years' => [1999, 2012], 'codes' => ['P0420', 'P0171', 'P0128'],
         'note' => "2.5L EJ25: P0420 is very common (check the rear O2 sensor and exhaust leaks first); also watch for external head-gasket seepage (oil/coolant at the head-to-block seam) on high-mileage engines."],
        ['make' => '/jeep|dodge|chrysler|ram/i', 'years' => [2011, 2013], 'codes' => ['P0300', 'P0302', 'P0304', 'P0306'],
         'note' => "3.6L Pentastar: misfires on the left bank (cylinders 2, 4, 6) with ticking were a known left cylinder head valve-seat issue (extended warranty on many). Have the VIN checked for coverage."],
        ['make' => '/jeep|dodge|chrysler|ram/i', 'years' => [2014, 2020], 'codes' => ['P0521', 'P0522', 'P0523', 'P0520'],
         'note' => "3.6L Pentastar: the plastic oil filter housing (with oil pressure sensor and cooler) cracks and leaks; oil pressure sensor codes often come from it. Check for oil in the engine valley."],
        ['make' => '/hyundai|kia/i', 'years' => [2011, 2019], 'codes' => ['P1326', 'P0325', 'P0300', 'P0301', 'P0302', 'P0303', 'P0304'],
         'note' => "Theta II 2.0T/2.4L: connecting-rod bearing failure is a known issue; P1326 (knock sensor detection system) means the engine has detected bearing wear. Hyundai/Kia extended engine coverage on many VINs: go to a dealer, do not keep driving with knocking."],
    ];
}
function obd_patterns_for(array $veh, string $code): array {
    $out = [];
    $make = (string)($veh['make'] ?? ''); $model = (string)($veh['model'] ?? ''); $year = (int)($veh['year'] ?? 0);
    if ($make === '') return [];
    foreach (obd_vehicle_patterns() as $p) {
        if (!in_array($code, $p['codes'], true) || !preg_match($p['make'], $make)) continue;
        if (!empty($p['model']) && !preg_match($p['model'], $model)) continue;
        if ($year && ($year < $p['years'][0] || $year > $p['years'][1])) continue;
        $out[] = $p['note'];
    }
    return $out;
}

/** Everything known about one code, for one vehicle (or none). */
function obd_lookup(string $code, array $veh = []): array {
    $code = obd_norm($code) ?? strtoupper($code);
    $map = obd_codes_map(); $kb = obd_kb();
    $st = preg_match('/^[PBCU][0-3][0-9A-F]{3}$/', $code) ? obd_structure($code) : ['system' => 'Unknown', 'generic' => false, 'area' => ''];
    $title = $map[$code][0] ?? null; $fam = $map[$code][1] ?? null;
    $lib = null;
    if (function_exists('db')) { try { $lib = row('SELECT * FROM obd_codes WHERE code=?', [$code]); } catch (Throwable $e) { $lib = null; } }
    if (!$title && $lib) $title = $lib['title'];
    if (!$fam) {
        $fam = match (true) {
            $code[0] === 'U' => 'can_bus',
            $code[0] === 'B' && $code[1] === '0' && hexdec(substr($code, 2)) < 0x100 => 'srs',
            !$st['generic'] => 'manufacturer',
            default => null,
        };
    }
    $k = $fam ? ($kb[$fam] ?? null) : null;
    $known = (bool)($title || $k);
    return [
        'code' => $code, 'title' => $title ?: (($st['area'] ?: preg_replace('/\s*\(.*$/', '', $st['system'])) . ($st['generic'] ? ' code' : ' code (manufacturer-specific)')),
        'family' => $fam, 'kb' => $k, 'structure' => $st, 'known' => $known, 'library' => $lib,
        'sev' => (int)($k['sev'] ?? ($lib['severity'] ?? 2)),
        'patterns' => obd_patterns_for($veh, $code),
    ];
}

/** Reasoning across several codes at once: what they point to together, and what to fix first. */
function obd_combo_insights(array $codes, array $veh = []): array {
    $has = fn(string ...$c) => (bool)array_intersect($c, $codes);
    $count = fn(string $re) => count(array_filter($codes, fn($c) => preg_match($re, $c)));
    $out = [];
    $misfire = $count('/^P030\d|^P031[0-2]$/');
    $leanB1 = $has('P0171', 'P2187', 'P1131'); $leanB2 = $has('P0174', 'P2189', 'P1151');
    $voltage = $has('P0562', 'P0560', 'P0561', 'P0563');
    if ($voltage) $out[] = ['first', 'Fix the battery/charging problem first', "A low or unstable system voltage makes modules set random codes. Test the battery and alternator, fix that, clear all codes, then see which ones come back."];
    if ($leanB1 && $leanB2) $out[] = ['cause', 'Both banks lean: look for one cause they share', "When both sides of a V engine run lean the cause is common to both: an air leak after the MAF (intake boot, PCV system, brake booster hose), a dirty/under-reading MAF, or low fuel pressure. It is almost never two bad O2 sensors."];
    elseif ($leanB1 xor $leanB2) if (in_array(strtolower((string)($veh['engine'] ?? '')), [''], true) || preg_match('/v6|v8|v10|v12|w12|boxer|flat/i', (string)($veh['engine'] ?? '') . ' ' . (string)($veh['model'] ?? ''))) $out[] = ['cause', 'Only one bank lean: look on that side', "Something specific to that bank: an intake gasket or vacuum leak on that side, an exhaust leak ahead of that bank's O2 sensor, or an injector on that bank."];
    if ($has('P0172') && $has('P0175')) $out[] = ['cause', 'Both banks rich: shared cause', "Check the EVAP purge valve (stuck open), fuel pressure (regulator), an over-reading MAF and the coolant temperature sensor before anything bank-specific."];
    if ($misfire && ($leanB1 || $leanB2)) $out[] = ['cause', 'Misfire together with lean codes', "The misfire is probably caused by the lean mixture (vacuum leak or low fuel pressure). Fix the lean condition first; new coils will not cure it."];
    if ($count('/^P030[1-9]|^P031[0-2]$/') >= 2) $out[] = ['cause', 'Several cylinders misfiring', "More than one cylinder points at something they share: fuel pressure, a vacuum leak, worn plugs across the set, timing (chain stretch), or bad fuel. A single bad coil only affects its own cylinder."];
    foreach ($codes as $c) if (preg_match('/^P03(0[1-9]|1[0-2])$/', $c)) {
        $n = (int)substr($c, 3);
        $inj = 'P02' . sprintf('%02d', $n); $coil = 'P03' . (50 + $n);
        if (in_array($inj, $codes, true)) $out[] = ['cause', "Cylinder $n misfire + injector circuit $inj", "The misfire on cylinder $n is explained by its injector circuit fault: check that injector and its connector first."];
        if (in_array($coil, $codes, true)) $out[] = ['cause', "Cylinder $n misfire + coil circuit $coil", "The coil on cylinder $n is the likely cause: swap-test or replace it."];
    }
    if ($has('P0420', 'P0430') && ($misfire || $leanB1 || $leanB2 || $has('P0172', 'P0175'))) $out[] = ['first', 'Fix the engine before the catalytic converter', "The catalyst code is not trustworthy while the engine misfires or runs rich/lean, and those faults are what kill converters. Fix them, drive a week, then re-check P0420/P0430."];
    if ($has('P0016', 'P0017', 'P0018', 'P0019', 'P0008', 'P0009') && $has('P0011', 'P0012', 'P0014', 'P0021', 'P0022', 'P0024')) $out[] = ['cause', 'Cam timing + crank/cam correlation together', "Points at the timing chain (stretch, tensioner, guides) or oil supply to the cam adjusters. Check oil level/condition, then correlation values; do not keep driving with chain rattle."];
    if ($has('P0335', 'P0336') && $has('P0340', 'P0341', 'P0345', 'P0365')) $out[] = ['cause', 'Crank and cam sensor codes together', "Both sensors failing at once is rare: suspect their shared 5 V supply or ground, a wiring problem, or the timing (chain jumped)."];
    if ($count('/^P0(12[0-4]|22[0-9])$|^P21(2[0-9]|3[0-8])$/') >= 2) $out[] = ['cause', 'Several throttle/pedal sensor codes', "Throttle and pedal sensors share a 5 V reference: a short in one sensor or its wiring can pull them all down. Check the reference voltage and connectors before replacing parts."];
    $u = $count('/^U/');
    if ($u >= 2) $out[] = ['cause', "$u communication codes", "Lost communication with several modules usually means a network, power or ground fault (battery, a corroded connector, a module dragging the bus down), not several bad modules. Start with battery voltage and grounds, and unplug aftermarket OBD devices."];
    if ($has('P0700') && count($codes) > 1) $out[] = ['info', 'P0700 is a messenger', "P0700 only says the transmission computer has its own code. Read the transmission module directly; the other transmission codes listed here may be that detail."];
    elseif ($has('P0700')) $out[] = ['info', 'Read the transmission module', "With only P0700 you still do not know the fault: have the transmission module read with a brand-capable scanner."];
    if ($has('P0442', 'P0455', 'P0456', 'P0457', 'P0440', 'P1456')) $out[] = ['first', 'EVAP leak: start with the gas cap', "Cheap first step: check/replace the gas cap, clear the code and drive a week. The leak test only runs under certain conditions, so give it time."];
    if ($has('P0125', 'P0128') && $has('P0171', 'P0174', 'P0300')) $out[] = ['info', 'Engine not warming up', "A thermostat stuck open keeps the engine cold, which affects fuel control. Fix the thermostat and re-check the other codes."];
    if ($has('P0101', 'P0102', 'P0103') && ($leanB1 || $leanB2)) $out[] = ['cause', 'MAF code together with lean codes', "The airflow sensor is the most likely cause of the lean codes: clean or replace it and check the air boot after it."];
    if ($count('/^P00(3[0-9]|4[0-4]|5[0-9]|6[0-4])$|^P01(35|41|47|55|61|67)$/') >= 2) $out[] = ['cause', 'Several O2 heater codes', "More than one heater failing at once usually means their shared fuse/relay or power feed, not several sensors."];
    if ($has('P0087') && $misfire) $out[] = ['cause', 'Low fuel rail pressure with misfire', "On direct-injection engines a weak high-pressure fuel pump causes both. Check desired vs actual rail pressure under load."];
    if ($has('P0730', 'P0731', 'P0732', 'P0733', 'P0734', 'P0735', 'P0736', 'P0868')) $out[] = ['first', 'Transmission slipping: check fluid first', "Before any repair, check the transmission fluid level and condition; low fluid causes ratio and pressure codes and is the cheapest fix."];
    if ($has('P0507', 'P0506') && ($leanB1 || $leanB2)) $out[] = ['cause', 'High idle + lean', "Classic vacuum leak signature. Smoke-test the intake and PCV system."];
    return $out;
}

/**
 * Freeze frame / live data: normalise "name: value" pairs from a scan report and explain what they say.
 * Returns ['values' => [key => float], 'notes' => [[level, text], ...]].
 */
function obd_freeze_parse(string $text): array {
    $alias = [
        'rpm' => '/engine\s*(speed|rpm)|^rpm$|\brpm\b/i', 'speed' => '/vehicle\s*speed|^speed$/i', 'ect' => '/coolant\s*temp|\bect\b/i', 'iat' => '/intake\s*air\s*temp|\biat\b/i',
        'load' => '/calc(ulated)?\.?\s*load|engine\s*load|absolute\s*load/i', 'maf' => '/\bmaf\b|mass\s*air\s*flow|air\s*flow\s*rate/i', 'map' => '/\bmap\b|intake\s*manifold\s*(absolute\s*)?pressure|manifold\s*pressure/i',
        'stft1' => '/short\s*term\s*fuel\s*trim.*(bank\s*1|b1)|fuel\s*trim.*(bank\s*1|b1).*short|\bstft\s*(b|bank\s*)?1|\bstft\b(?!.*2)/i', 'ltft1' => '/long\s*term\s*fuel\s*trim.*(bank\s*1|b1)|fuel\s*trim.*(bank\s*1|b1).*long|\bltft\s*(b|bank\s*)?1|\bltft\b(?!.*2)/i',
        'stft2' => '/short\s*term\s*fuel\s*trim.*(bank\s*2|b2)|fuel\s*trim.*(bank\s*2|b2).*short|\bstft\s*(b|bank\s*)?2/i', 'ltft2' => '/long\s*term\s*fuel\s*trim.*(bank\s*2|b2)|fuel\s*trim.*(bank\s*2|b2).*long|\bltft\s*(b|bank\s*)?2/i',
        'volt' => '/control\s*module\s*voltage|module\s*voltage|battery\s*voltage|system\s*voltage|^\s*voltage\b|voltage\s*\(control\s*module\)/i', 'tps' => '/throttle\s*pos/i', 'timing' => '/timing\s*advance|ignition\s*timing/i',
        'runtime' => '/run\s*time|time\s*since\s*(engine\s*)?start/i', 'dist_clear' => '/distance.*(since|after).*(clear|cleared)|km\s*since\s*codes|miles\s*since\s*codes/i', 'warmups' => '/warm.?ups?\s*since/i',
        'dist_mil' => '/distance.*mil/i', 'baro' => '/barometric|baro\b/i', 'fuel_status' => '/fuel\s*system\s*status|fuel\s*status/i', 'rail' => '/fuel\s*rail\s*pressure|rail\s*pressure/i',
    ];
    $vals = []; $raw = [];
    foreach (preg_split('/\R/', $text) as $line) {
        if (!preg_match('/^\s*([^:=\t]{2,60}?)\s*[:=\t]\s*([-+]?\d+(?:[.,]\d+)?)\s*([%a-z°\/\.]*)/i', $line, $mm)
            && !preg_match('/^\s*([A-Za-z][A-Za-z0-9 \/().#-]{2,60}?)\s{2,}([-+]?\d+(?:[.,]\d+)?)\s*([%a-z°\/\.]*)/i', $line, $mm)) {
            if (preg_match('/fuel\s*system\s*(1\s*)?status\s*[:=\t]\s*(.+)$/i', $line, $fs)) $raw['fuel_status'] = trim($fs[2]);
            continue;
        }
        $name = trim($mm[1]); $v = (float)str_replace(',', '.', $mm[2]); $unit = strtolower($mm[3]);
        foreach ($alias as $k => $re) if (!isset($vals[$k]) && preg_match($re, $name)) {
            // Convert Fahrenheit temperatures and kPa/psi as needed.
            if (in_array($k, ['ect', 'iat'], true) && (str_contains($unit, 'f') || $v > 130)) $v = round(($v - 32) * 5 / 9, 1);
            if (in_array($k, ['map', 'baro'], true) && str_contains($unit, 'psi')) $v = round($v * 6.895, 1);
            if (in_array($k, ['map', 'baro'], true) && (str_contains($unit, 'inhg') || str_contains($unit, 'in'))) $v = round($v * 3.386, 1);
            if (in_array($k, ['speed', 'dist_clear', 'dist_mil'], true) && str_contains($unit, 'km')) $v = round($v * 0.621, 1);
            if ($k === 'maf' && str_contains($unit, 'lb')) $v = round($v * 7.56, 2);
            $vals[$k] = $v; break;
        }
    }
    // Reports whose text runs together (PDF tables): find "name value unit" anywhere in the text.
    if (count($vals) < 2 && preg_match_all('/(\p{L}[\p{L}0-9 .\/()#-]{2,48}?)\s+([-+]?\d+(?:[.,]\d+)?)\s*\S{0,2}?(%|rpm|°\s*[CF]|[CF]\b|g\/s|kpa|psi|v\b|km\/h|mph)/iu', $text, $all, PREG_SET_ORDER)) {
        $lines = implode("\n", array_map(fn($m) => trim($m[1]) . ': ' . $m[2] . ' ' . $m[3], $all));
        if ($lines !== '' && $lines !== $text) { $more = obd_freeze_parse($lines); $vals += $more['values']; }
    }
    return ['values' => $vals, 'raw' => $raw];
}
function obd_freeze_notes(array $vals, array $veh = [], array $raw = []): array {
    $n = []; $v = fn(string $k) => $vals[$k] ?? null;
    $liters = (float)preg_replace('/[^\d.]/', '', (string)preg_match('/(\d\.\d)\s*l/i', (string)($veh['engine'] ?? ''), $lm) ? $lm[1] : '0');
    $idle = $v('rpm') !== null && $v('rpm') < 1000;
    $t1 = ($v('stft1') ?? 0) + ($v('ltft1') ?? 0); $t2 = ($v('stft2') ?? 0) + ($v('ltft2') ?? 0);
    $hasT1 = $v('stft1') !== null || $v('ltft1') !== null; $hasT2 = $v('stft2') !== null || $v('ltft2') !== null;
    if ($hasT1) {
        $s = sprintf('%+.1f%%', $t1);
        if ($t1 > 20) $n[] = ['bad', "Bank 1 total fuel trim $s: strongly lean (the computer is adding a lot of fuel)."];
        elseif ($t1 > 10) $n[] = ['warn', "Bank 1 total fuel trim $s: lean."];
        elseif ($t1 < -20) $n[] = ['bad', "Bank 1 total fuel trim $s: strongly rich (pulling a lot of fuel)."];
        elseif ($t1 < -10) $n[] = ['warn', "Bank 1 total fuel trim $s: rich."];
        else $n[] = ['ok', "Bank 1 total fuel trim $s: normal (within ±10%)."];
    }
    if ($hasT2) {
        $s = sprintf('%+.1f%%', $t2);
        $n[] = $t2 > 10 ? ['warn', "Bank 2 total fuel trim $s: lean."] : ($t2 < -10 ? ['warn', "Bank 2 total fuel trim $s: rich."] : ['ok', "Bank 2 total fuel trim $s: normal."]);
        if ($hasT1 && abs($t1 - $t2) > 10) $n[] = ['info', 'The two banks differ by more than 10%: the cause is probably on one side (intake gasket, injector or exhaust leak on that bank).'];
        if ($t1 > 10 && $t2 > 10) $n[] = ['info', 'Both banks lean: look for a shared cause (MAF, air leak after the MAF, fuel pressure).'];
    }
    if (($t1 > 10 || $t2 > 10) && $idle) $n[] = ['info', 'Lean at idle: if the trims come back toward 0 at 2,500 rpm it is a vacuum leak; if they stay lean or get worse under load, suspect fuel delivery or the MAF.'];
    if (($e = $v('ect')) !== null) {
        if ($e > 108) $n[] = ['bad', "Coolant at {$e} °C: overheating. Stop and check coolant and fans."];
        elseif ($e < 70 && (($v('runtime') ?? 0) > 600 || ($v('speed') ?? 0) > 40)) $n[] = ['warn', "Coolant only {$e} °C after driving: thermostat probably stuck open (P0128)."];
        elseif ($e < 60) $n[] = ['info', "Coolant {$e} °C: engine was cold when the code set (open-loop fuel control is normal when cold)."];
        else $n[] = ['ok', "Coolant {$e} °C: normal operating temperature."];
    }
    if (($vv = $v('volt')) !== null) {
        $running = ($v('rpm') ?? 0) > 400;
        if ($running && $vv < 13.0) $n[] = ['bad', "Module voltage {$vv} V with the engine running: the battery is not being charged properly (alternator, belt or wiring)."];
        elseif ($running && $vv > 15.0) $n[] = ['bad', "Module voltage {$vv} V: overcharging (alternator regulator)."];
        elseif (!$running && $vv < 12.2) $n[] = ['warn', "Voltage {$vv} V engine off: battery is low or weak."];
        else $n[] = ['ok', "Voltage {$vv} V: normal."];
    }
    if (($m = $v('maf')) !== null && $idle && $liters > 0) {
        $ratio = $m / $liters;
        if ($ratio < 0.6) $n[] = ['warn', "MAF {$m} g/s at idle is low for a {$liters} L engine (about {$liters} g/s expected warm): MAF under-reading (dirty) or air entering after the MAF."];
        elseif ($ratio > 1.8) $n[] = ['info', "MAF {$m} g/s at idle is high for a {$liters} L engine: heavy load (A/C, cold engine) or MAF over-reading."];
        else $n[] = ['ok', "MAF {$m} g/s at idle looks right for a {$liters} L engine."];
    } elseif (($m = $v('maf')) !== null && $idle) $n[] = ['info', "MAF {$m} g/s at idle: as a rule of thumb a warm engine idles at about 1 g/s per litre of engine size."];
    if (($p = $v('map')) !== null && $idle) {
        if ($p > 45) $n[] = ['warn', "Manifold pressure {$p} kPa at idle: low vacuum (normal is about 25-35 kPa warm). Vacuum leak, late cam timing, or a restricted exhaust."];
        else $n[] = ['ok', "Manifold pressure {$p} kPa at idle: normal vacuum."];
    }
    if (($l = $v('load')) !== null && $idle && $l > 45) $n[] = ['info', "Calculated load {$l}% at idle is high (typical warm idle 20-35%)."];
    if (($d = $v('dist_clear')) !== null) {
        if ($d < 100) $n[] = ['warn', "Only " . round($d) . " miles since codes were cleared. Monitors may not be ready yet. Buying a car? Someone may have cleared codes recently."];
        else $n[] = ['info', round($d) . ' miles since codes were last cleared.'];
    }
    if (($w = $v('warmups')) !== null && $w < 3) $n[] = ['info', "Only {$w} warm-up cycles since codes were cleared: readiness monitors are probably incomplete."];
    if (($d = $v('dist_mil')) !== null && $d > 0) $n[] = ['info', round($d) . ' miles driven with the check-engine light on.'];
    if (!empty($raw['fuel_status']) && preg_match('/\bOL\b|open\s*loop/i', $raw['fuel_status']) && ($v('ect') ?? 0) > 75) $n[] = ['warn', 'Fuel system in open loop while warm: the computer is ignoring the O2 sensors (sensor fault or a fault that forces open loop).'];
    return $n;
}

/** Links to free knowledge for a code on a vehicle (videos are where most DIY repairs are learned). */
function obd_links(string $code, array $veh = []): array {
    $v = trim(($veh['year'] ?? '') . ' ' . ($veh['make'] ?? '') . ' ' . ($veh['model'] ?? ''));
    $q = trim($code . ' ' . $v);
    return [
        ['Videos', 'https://www.youtube.com/results?search_query=' . urlencode($q . ' fix')],
        ['Forums & fixes', 'https://www.google.com/search?q=' . urlencode($q . ' forum fix')],
        ['Code definition', 'https://www.obd-codes.com/' . strtolower($code)],
    ];
}
