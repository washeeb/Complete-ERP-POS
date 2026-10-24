<?php

namespace App\Domain\Pharmacy;

/**
 * A starter list of well-known interactions between medicines common in community pharmacies (owner request
 * 2026-10-24), so warnings work from day one. NOT a complete reference and not checked for this pharmacy: the
 * superintendent pharmacist reviews it (Pharmacy → Interactions), changes or removes rows, and adds others.
 * Ingredient names are international generic names, lower case, as on the medicines' generic name or active
 * ingredients; a combination with its own generic name (co-trimoxazole) is listed under that name too.
 *
 * Rows: [ingredient, interacts with, severity, what to do]
 */
final class InteractionStarter
{
    private const NSAID_BLEEDING = 'Bleeding risk (stomach and gut). Offer paracetamol for pain instead, or check with the prescriber.';

    private const NITRATE = 'Dangerous fall in blood pressure. Never dispense together.';

    private const STATIN_MACROLIDE = 'Risk of severe muscle damage. The statin is usually paused during the course; check with the prescriber.';

    private const SPACE_CIPRO = 'Stops ciprofloxacin being absorbed. Take ciprofloxacin 2 hours before or 6 hours after it.';

    private const SPACE_DOXY = 'Stops doxycycline being absorbed. Take doxycycline 2 hours before or after it.';

    private const PILL = 'The contraceptive pill may fail. Advise an extra method (condoms) during treatment and for 4 weeks after.';

    private const POTASSIUM = 'Potassium can rise too high. Given together only with blood tests: confirm with the prescriber.';

    public const ROWS = [
        ['warfarin', 'aspirin', 'major', 'Higher risk of bleeding. Do not dispense together unless the prescriber asked for both.'],
        ['warfarin', 'ibuprofen', 'major', self::NSAID_BLEEDING],
        ['warfarin', 'diclofenac', 'major', self::NSAID_BLEEDING],
        ['warfarin', 'naproxen', 'major', self::NSAID_BLEEDING],
        ['warfarin', 'metronidazole', 'major', 'Raises the effect of warfarin: bleeding risk. Check with the prescriber; the INR needs checking.'],
        ['warfarin', 'fluconazole', 'major', 'Raises the effect of warfarin: bleeding risk. Check with the prescriber.'],
        ['warfarin', 'sulfamethoxazole', 'major', 'Co-trimoxazole raises the effect of warfarin: bleeding risk. Check with the prescriber.'],
        ['warfarin', 'co-trimoxazole', 'major', 'Co-trimoxazole raises the effect of warfarin: bleeding risk. Check with the prescriber.'],
        ['warfarin', 'ciprofloxacin', 'moderate', 'May raise the effect of warfarin. Tell the patient to watch for bleeding or bruising; an INR check is advised.'],
        ['warfarin', 'erythromycin', 'moderate', 'May raise the effect of warfarin. Tell the patient to watch for bleeding or bruising.'],
        ['warfarin', 'clarithromycin', 'moderate', 'May raise the effect of warfarin. Tell the patient to watch for bleeding or bruising.'],
        ['methotrexate', 'trimethoprim', 'major', 'Serious harm to the blood. Do not dispense together; refer to the prescriber.'],
        ['methotrexate', 'co-trimoxazole', 'major', 'Serious harm to the blood. Do not dispense together; refer to the prescriber.'],
        ['azathioprine', 'allopurinol', 'major', 'Azathioprine builds up: serious harm to the blood. Refer to the prescriber.'],
        ['sildenafil', 'glyceryl trinitrate', 'major', self::NITRATE],
        ['sildenafil', 'isosorbide dinitrate', 'major', self::NITRATE],
        ['sildenafil', 'isosorbide mononitrate', 'major', self::NITRATE],
        ['tadalafil', 'glyceryl trinitrate', 'major', self::NITRATE],
        ['tadalafil', 'isosorbide dinitrate', 'major', self::NITRATE],
        ['tadalafil', 'isosorbide mononitrate', 'major', self::NITRATE],
        ['simvastatin', 'clarithromycin', 'major', self::STATIN_MACROLIDE],
        ['simvastatin', 'erythromycin', 'major', self::STATIN_MACROLIDE],
        ['simvastatin', 'ketoconazole', 'major', self::STATIN_MACROLIDE],
        ['simvastatin', 'itraconazole', 'major', self::STATIN_MACROLIDE],
        ['digoxin', 'amiodarone', 'major', 'Digoxin builds up (sickness, slow or irregular pulse). The prescriber usually lowers the digoxin dose.'],
        ['lithium', 'ibuprofen', 'major', 'Lithium can build up to harmful levels. Offer paracetamol instead, or check with the prescriber.'],
        ['lithium', 'diclofenac', 'major', 'Lithium can build up to harmful levels. Offer paracetamol instead, or check with the prescriber.'],
        ['lithium', 'hydrochlorothiazide', 'major', 'Lithium can build up to harmful levels. Check with the prescriber.'],
        ['theophylline', 'ciprofloxacin', 'major', 'Theophylline builds up (fast heartbeat, fits). Check with the prescriber.'],
        ['spironolactone', 'lisinopril', 'moderate', self::POTASSIUM],
        ['spironolactone', 'enalapril', 'moderate', self::POTASSIUM],
        ['spironolactone', 'ramipril', 'moderate', self::POTASSIUM],
        ['spironolactone', 'losartan', 'moderate', self::POTASSIUM],
        ['spironolactone', 'potassium chloride', 'major', 'Potassium can rise dangerously. Do not dispense together without the prescriber.'],
        ['lisinopril', 'potassium chloride', 'moderate', self::POTASSIUM],
        ['enalapril', 'potassium chloride', 'moderate', self::POTASSIUM],
        ['clopidogrel', 'omeprazole', 'moderate', 'Omeprazole weakens clopidogrel. Another stomach medicine (e.g. pantoprazole) is usually preferred; check with the prescriber.'],
        ['ciprofloxacin', 'aluminium hydroxide', 'moderate', self::SPACE_CIPRO],
        ['ciprofloxacin', 'magnesium hydroxide', 'moderate', self::SPACE_CIPRO],
        ['ciprofloxacin', 'calcium carbonate', 'moderate', self::SPACE_CIPRO],
        ['ciprofloxacin', 'ferrous sulfate', 'moderate', self::SPACE_CIPRO],
        ['doxycycline', 'aluminium hydroxide', 'moderate', self::SPACE_DOXY],
        ['doxycycline', 'magnesium hydroxide', 'moderate', self::SPACE_DOXY],
        ['doxycycline', 'calcium carbonate', 'moderate', self::SPACE_DOXY],
        ['doxycycline', 'ferrous sulfate', 'moderate', self::SPACE_DOXY],
        ['rifampicin', 'ethinylestradiol', 'major', self::PILL],
        ['carbamazepine', 'ethinylestradiol', 'major', self::PILL],
        ['tramadol', 'fluoxetine', 'moderate', 'Risk of serotonin syndrome and fits. Check with the prescriber.'],
        ['tramadol', 'sertraline', 'moderate', 'Risk of serotonin syndrome and fits. Check with the prescriber.'],
        ['tramadol', 'amitriptyline', 'moderate', 'Risk of fits and serotonin syndrome. Check with the prescriber.'],
        ['ibuprofen', 'diclofenac', 'moderate', 'Two anti-inflammatory painkillers: stomach bleeding risk with no extra benefit. Give one only.'],
        ['prednisolone', 'ibuprofen', 'moderate', 'Stomach ulcer and bleeding risk. Prefer paracetamol, or check with the prescriber.'],
        ['prednisolone', 'diclofenac', 'moderate', 'Stomach ulcer and bleeding risk. Prefer paracetamol, or check with the prescriber.'],
        ['glibenclamide', 'fluconazole', 'moderate', 'Blood sugar may fall too low. Tell the patient the signs of low sugar (sweating, shaking) and what to do.'],
    ];
}
