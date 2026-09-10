<?php
namespace AIWorkforce\Sports;

/** Produces versioned features only from explicitly verified numeric inputs. */
class FeatureEngineeringEngine
{
    public const VERSION = 'sports-features-v1';

    /** Verified numeric form inputs the features are built from. */
    public const REQUIRED_FORM_FIELDS = ['homeGoalsPerMatch', 'awayGoalsPerMatch', 'homeConcededPerMatch', 'awayConcededPerMatch'];

    public function build(array $intelligence): array
    {
        if (($intelligence['decision'] ?? '') !== 'INTELLIGENCE_READY') {
            return ['ok' => false, 'reason' => 'INSUFFICIENT_DATA', 'version' => self::VERSION, 'features' => [], 'missingFields' => ['recentForm']];
        }
        $form = $intelligence['inputs']['recentForm'] ?? null;
        $missing = [];
        foreach (self::REQUIRED_FORM_FIELDS as $key) {
            if (!is_array($form) || !isset($form[$key]) || !is_numeric($form[$key])) $missing[] = 'recentForm.' . $key;
        }
        if ($missing !== []) return ['ok' => false, 'reason' => 'INSUFFICIENT_DATA', 'version' => self::VERSION, 'features' => [], 'missingFields' => $missing];
        $features = [
            'expectedGoalsProxy' => round(((float)$form['homeGoalsPerMatch'] + (float)$form['awayGoalsPerMatch'] + (float)$form['homeConcededPerMatch'] + (float)$form['awayConcededPerMatch']) / 2, 4),
            'homeAttack' => (float) $form['homeGoalsPerMatch'], 'awayAttack' => (float) $form['awayGoalsPerMatch'],
            'homeDefenseConceded' => (float) $form['homeConcededPerMatch'], 'awayDefenseConceded' => (float) $form['awayConcededPerMatch'],
        ];
        return ['ok' => true, 'version' => self::VERSION, 'features' => $features, 'inputSources' => ['recentForm' => $form['source'] ?? null]];
    }
}
