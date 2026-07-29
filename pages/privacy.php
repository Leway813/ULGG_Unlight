<?php
require_once __DIR__ . '/../config.php';

$pageTitleText = '隱私權政策';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網';
$pageTitleFull = $pageTitleText . ' | UL.GG';
$activeMenu = ''; // 不需要高亮任何側邊欄

ob_start();
?>

<div class="container" style="max-width:900px;margin:40px auto;">
  <h1>隱私權政策</h1>

  <p class="text-muted">
    本頁說明 UL.GG（以下簡稱「本站」）對使用者資料與創作內容的處理方式。
  </p>

  <h3>一、使用者帳號與資料</h3>
  <p>
    本站僅會於必要情況下，蒐集與儲存使用者登入與操作所需之基本資訊，
    不會主動蒐集與本服務無關之個人資料。
  </p>

  <h3>二、創作內容（Fan Art / 咒語）</h3>
  <p>
    使用者上傳之圖像與文字內容，其創作權利仍屬原作者所有。
    使用者同意本站於非商業用途下，
    於 UNLIGHT 相關頁面中進行展示、投票、排名與功能呈現。
  </p>

  <h3>三、資料使用與保存</h3>
  <p>
    本站不會將使用者資料或創作內容出售、交換或提供予第三方。
    管理員保留因系統維護、違規處理或功能調整而進行內容管理之權利。
  </p>

  <h3>四、政策更新</h3>
  <p>
    本站可能因功能調整而更新本政策，更新後將直接公布於本頁面。
  </p>

  <p class="text-muted small">
    最後更新日期：<?= date('Y-m-d') ?>
  </p>
</div>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
