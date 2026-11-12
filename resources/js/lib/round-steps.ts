/**
 * The round counts the board offers under «Başla» (architecture §4 "Round
 * count"): every multiple of 5 from `min` to `max` (`BoardProps.roundLimits`),
 * plus the game's own count when it is none of them, so it is always shown
 * chosen.
 */
export function roundSteps(
    limits: { min: number; max: number },
    current: number,
): number[] {
    const steps: number[] = [];

    for (
        let count = Math.ceil(limits.min / 5) * 5;
        count <= limits.max;
        count += 5
    ) {
        steps.push(count);
    }

    if (
        !steps.includes(current) &&
        current >= limits.min &&
        current <= limits.max
    ) {
        steps.push(current);
        steps.sort((a, b) => a - b);
    }

    return steps;
}
