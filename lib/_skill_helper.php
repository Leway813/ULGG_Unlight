<?php
const UL_SLOT_LABEL = [
  0 => '劍',
  1 => '槍',
  2 => '盾',
  3 => '移',
  4 => '特',
  5 => '花',
  6 => '黑',
  7 => '無',
];

const UL_PHASE_LABEL = [
  0 => 'ATK',
  1 => 'DEF',
  2 => 'MOV',
];

const UL_RANGE_LABEL = [
  '0' => '近',
  '1' => '中',
  '2' => '遠',
];

const UL_REQUIRE_TYPE_LABEL = [
  0 => '劍',
  1 => '槍',
  2 => '盾',
  3 => '移',
  4 => '特',
  5 => '任意',
  '0,1' => '[劍,槍]',
  '0,1,2' => '[劍,槍,盾]',
];

const UL_REQUIRE_NUM_LABEL = [
  0 => '至少',
  1 => '等於',
  2 => '至多',
];

const UL_REQUIRE_TYPE_CLASS = [
  0 => 'req-type-sword',
  1 => 'req-type-gun',
  2 => 'req-type-shield',
  3 => 'req-type-move',
  4 => 'req-type-spec',
  5 => 'req-type-any',
  '0,1' => 'req-type-sword-gun',
  '0,1,2' => 'req-type-sword-gun',
];

function ul_format_skill_require(?string $json): ?string
{
  if (!$json) return null;

  $data = json_decode($json, true);
  if (!$data) return null;

  if (isset($data['require'])) {
    $requires = $data['require'];
  } elseif (isset($data['cost'])) {
    $requires = $data['cost'];
  } elseif (is_array($data)) {
    $requires = $data;
  } else {
    return null;
  }

  if (empty($requires)) return null;

  $parts = [];

  foreach ($requires as $r) {
    if (!isset($r['type'], $r['quantity'])) continue;

    $qty = (int)$r['quantity'];
    $num = (int)($r['num'] ?? 0);

    $typeRaw = $r['type'];
    if (is_array($typeRaw)) {
      sort($typeRaw);
      $typeKey = implode(',', $typeRaw);
    } else {
      $typeKey = (string)(int)$typeRaw;
    }

    $cls = UL_REQUIRE_TYPE_CLASS[$typeKey] ?? 'req-type-any';
    $typeLabel = UL_REQUIRE_TYPE_LABEL[$typeKey] ?? '任意';
    $opLabel = UL_REQUIRE_NUM_LABEL[$num] ?? '至少';

    $tooltip = sprintf('%s %s %d', $typeLabel, $opLabel, $qty);

    $opIcon = match ($num) {
      1 => '=',
      2 => '↓',
      default => '↑',
    };

    $parts[] = sprintf(
      '<span class="req-chip %s" title="%s">
        <span class="req-num">%d</span>
        <span class="req-op">%s</span>
      </span>',
      $cls,
      htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8'),
      $qty,
      $opIcon
    );
  }

  return implode('', $parts);
}

function ul_format_phase(?int $phase): ?string
{
  return UL_PHASE_LABEL[$phase] ?? null;
}

function ul_card_level_label(int $rarity, int $officialLevel): string
{
  return ($rarity <= 5 ? 'L' : 'R') . $officialLevel;
}

function ul_card_rank_order(int $rarity, int $officialLevel): int
{
  return $rarity <= 5 ? $officialLevel : 5 + $officialLevel;
}
