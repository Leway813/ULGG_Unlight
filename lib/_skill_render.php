<?php

require_once __DIR__ . '/_skill_helper.php';

function h_skill($v): string
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function ul_render_range_badges(?string $rangeMask): string
{
  $ranges = ($rangeMask !== null && $rangeMask !== '')
    ? explode(',', $rangeMask)
    : [];

  if (!$ranges) return '';

  return '
    <div class="ul-range-row">
      <span class="range-badge range-far ' . (in_array('2', $ranges, true) ? 'on' : '') . '">▶</span>
      <span class="range-badge range-mid ' . (in_array('1', $ranges, true) ? 'on' : '') . '">▶</span>
      <span class="range-badge range-near ' . (in_array('0', $ranges, true) ? 'on' : '') . '">▶</span>
    </div>
  ';
}

function ul_render_skill_card(array $s, array $options = []): string
{
  $isAdmin = !empty($options['is_admin']);
  $showCharacter = !empty($options['show_character']);
  $showAvailable = !empty($options['show_available']);
  $tagBaseUrl = $options['tag_base_url'] ?? '/pages/skills.php';

  $skillCode = $s['skill_code'] ?? '';
  $skillName = $s['name_tcn'] ?? $skillCode;
  $info = trim((string)($s['info_tcn'] ?? ''));
  $note = trim((string)($s['note_player_tcn'] ?? ''));

  $phase = isset($s['phase']) ? (int)$s['phase'] : null;
  $phaseClass = match ($phase) {
    0 => 'atk',
    1 => 'def',
    2 => 'mov',
    default => 'atk',
  };
  $phaseText = UL_PHASE_LABEL[$phase] ?? 'UNK';

  $requireTxt = ul_format_skill_require($s['require_json'] ?? null);
  $rangeBadges = ul_render_range_badges($s['range_mask'] ?? null);

  $tags = $s['tags'] ?? [];
  if (is_string($tags)) {
    $tags = array_filter(array_map('trim', explode(',', $tags)));
  }

  ob_start();
?>
  <div class="ul-skill-card">
    <div class="ul-skill-header">
      <div class="ul-skill-title-row">
        <div class="ul-skill-title">
          <?= h_skill($skillName) ?>

          <?php if ($isAdmin && $skillCode): ?>
            <span class="text-muted small">｜<?= h_skill($skillCode) ?></span>
          <?php endif; ?>
        </div>

        <?php if ($isAdmin && $skillCode): ?>
          <button
            class="btn-add-tag"
            data-skill="<?= h_skill($skillCode) ?>"
            title="新增技能 Tag">
            +Tag
          </button>
        <?php endif; ?>
      </div>

      <?php if ($showCharacter): ?>
        <div class="text-muted small">
          <?= h_skill($s['chara_name'] ?? '') ?>
          <?php if (!empty($s['min_level_label'])): ?>
            ｜最低可用：<?= h_skill($s['min_level_label']) ?>
          <?php endif; ?>
          <?php if ($showAvailable && !empty($s['available_levels'])): ?>
            ｜可用：<?= h_skill($s['available_levels']) ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="ul-skill-tags" data-skill="<?= h_skill($skillCode) ?>">
        <?php foreach ($tags as $t): ?>
          <a
            href="<?= h_skill($tagBaseUrl) ?>?tag=<?= urlencode($t) ?>"
            class="skill-tag"
            title="查看標籤：<?= h_skill($t) ?>">
            <?= h_skill($t) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="ul-skill-body">
      <div class="ul-skill-meta">
        <span class="skill-badge <?= h_skill($phaseClass) ?>">
          <?= h_skill($phaseText) ?>
        </span>

        <?= $rangeBadges ?>

        <?php if ($requireTxt): ?>
          <div class="skill-require">
            <?= $requireTxt ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="ul-skill-desc">
        <div class="skill-info-official">
          <?= nl2br(h_skill($info !== '' ? $info : '（尚無技能說明）')) ?>
        </div>

        <?php if ($note !== ''): ?>
          <div class="text-muted small mt-2">
            <?= nl2br(h_skill($note)) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php
  return ob_get_clean();
}
