<?php
declare(strict_types=1);

/**
 * Turns the board into a prompt for an AI assistant: what each project is for,
 * exactly where it stands, and what kind of weekly plan to produce.
 *
 * The point of the "why" on each project is that a plan built only from card
 * titles can tell you what is outstanding but not what is worth your week.
 */

const PLAN_TASK_NOTE_LIMIT = 160;

/** One project's section: its purpose, then every column and what is in it. */
function plan_project_section(array $project, int $position): string
{
    $lines = ["### {$position}. {$project['title']}"];

    $why = trim((string) ($project['description'] ?? ''));
    $lines[] = $why !== ''
        ? "Why it matters to me: $why"
        : 'Why it matters to me: (not written down yet — treat this one with less weight, '
          . 'and ask me what it is for.)';

    if ($project['columns'] === []) {
        $lines[] = 'Where it stands: this board has no columns yet.';
        return implode("\n", $lines);
    }

    $lines[] = 'Where it stands:';

    foreach ($project['columns'] as $column) {
        $count = count($column['tasks']);

        if ($count === 0) {
            $lines[] = "- {$column['title']}: empty";
            continue;
        }

        $lines[] = "- {$column['title']} ({$count}):";
        foreach ($column['tasks'] as $task) {
            $line = "    - {$task['title']}";
            $note = trim(preg_replace('/\s+/u', ' ', (string) $task['description']) ?? '');
            if ($note !== '') {
                $line .= ' — ' . plan_shorten($note, PLAN_TASK_NOTE_LIMIT);
            }
            $lines[] = $line;
        }
    }

    return implode("\n", $lines);
}

function plan_shorten(string $text, int $limit): string
{
    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '…' : $text;
}

/**
 * Structural observations only — how many cards sit where. Deliberately avoids
 * guessing what a column *means*: "Done" and "Icebox" are both just names a
 * person chose, so the prompt reports the shape and lets the model interpret.
 */
function plan_observations(array $projects): array
{
    $notes = [];

    foreach ($projects as $project) {
        $total = 0;
        foreach ($project['columns'] as $column) {
            $total += count($column['tasks']);
        }

        if ($total === 0) {
            $notes[] = "\"{$project['title']}\" has no cards on it at all.";
            continue;
        }

        $first = $project['columns'][0] ?? null;
        if ($first !== null && count($first['tasks']) === $total && count($project['columns']) > 1) {
            $notes[] = "\"{$project['title']}\": all {$total} cards are still in the first column "
                . "(\"{$first['title']}\") — nothing has moved along.";
        }

        if (trim((string) ($project['description'] ?? '')) === '') {
            $notes[] = "\"{$project['title']}\" has no description of what it is for.";
        }
    }

    return $notes;
}

/**
 * Build the whole prompt.
 *
 * $options: 'hours' — roughly how much time is available this week;
 *           'notes' — anything else going on that should shape the plan.
 */
function plan_build_prompt(array $projects, array $options = []): string
{
    $hours = trim((string) ($options['hours'] ?? ''));
    $notes = trim((string) ($options['notes'] ?? ''));

    $cards = 0;
    foreach ($projects as $project) {
        foreach ($project['columns'] as $column) {
            $cards += count($column['tasks']);
        }
    }

    $out = [];

    $out[] = 'I am running several projects in parallel and I want help planning the week ahead, '
        . 'so that every one of them keeps moving instead of whichever is loudest taking all my time.';
    $out[] = '';
    $out[] = 'Today is ' . date('l, j F Y') . '. Plan for the coming week.';

    if ($hours !== '') {
        $out[] = 'Time I expect to have for these projects this week: ' . $hours . '.';
    }
    if ($notes !== '') {
        $out[] = 'Also worth knowing about this week: ' . $notes;
    }

    if ($projects === []) {
        $out[] = '';
        $out[] = 'I have no projects on my board yet. Ask me what I am trying to make progress on, '
            . 'and help me set up a small number of projects worth tracking.';
        return implode("\n", $out);
    }

    $out[] = '';
    $out[] = sprintf(
        'Below is every project I have, why it matters to me, and exactly where each one stands '
        . '(%d projects, %d cards in total).',
        count($projects),
        $cards
    );
    $out[] = '';

    foreach (array_values($projects) as $index => $project) {
        $out[] = plan_project_section($project, $index + 1);
        $out[] = '';
    }

    $observations = plan_observations($projects);
    if ($observations !== []) {
        $out[] = 'Things that stand out in the data above:';
        foreach ($observations as $observation) {
            $out[] = '- ' . $observation;
        }
        $out[] = '';
    }

    $out[] = 'What I would like from you:';
    $out[] = '';
    $out[] = '1. A plan for the coming week that keeps every project moving. Weight it by what '
        . 'actually matters to me, not by how much is piled up — and if a project genuinely '
        . 'should rest this week, say so and tell me why.';
    $out[] = '2. For each project I should touch, the single next action, specific enough that I '
        . 'can start it without having to decide anything else first.';
    $out[] = '3. A realistic shape for the week — roughly what to do on which days, given the time '
        . 'I have. Leave slack; do not fill every hour.';
    $out[] = '4. Anything that looks stalled, and the smallest step that would unstick it.';
    $out[] = '5. Where two projects want the same time, name the trade-off and make the call '
        . 'rather than leaving it to me.';
    $out[] = '6. Finish with a short, honest note connecting this week to what these projects are '
        . 'actually for, using my own reasons above in my own words. No generic encouragement — '
        . 'if I am spreading myself too thin, tell me that instead.';
    $out[] = '';
    $out[] = 'If anything above is ambiguous, or you need something from me to make the plan '
        . 'realistic, ask me before you write it.';

    return implode("\n", $out);
}
