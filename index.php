<?php
// =============================================
// 입찰 공고 모니터링 시스템 - index.php
// 좌측 필터 패널 + 우측 카드형 리스트
// =============================================

require_once 'config.php';
require_once 'db.php';
require_once 'api_fetch.php';

// 태그: 복수 선택. GET tag[] 또는 tags=1,2,3 또는 (구) tag=단일
$tags = [];
if (isset($_GET['tag'])) {
    if (is_array($_GET['tag'])) {
        $tags = array_filter(array_map('intval', $_GET['tag']));
    } else {
        $tags = (int)$_GET['tag'] ? [(int)$_GET['tag']] : [];
    }
} elseif (!empty($_GET['tags'])) {
    $tags = array_filter(array_map('intval', explode(',', (string)$_GET['tags'])));
}
$filters = [
    'search'   => trim($_GET['search'] ?? ''),
    'source'   => trim($_GET['source'] ?? ''),
    'sources'  => isset($_GET['sources']) && $_GET['sources'] !== '' ? array_filter(array_map('trim', explode(',', (string)$_GET['sources']))) : [],
    'deadline' => trim($_GET['deadline'] ?? ''),
    'tags'     => $tags,
    'from'     => trim($_GET['from'] ?? ''),
    'to'       => trim($_GET['to'] ?? ''),
    'sort'     => trim($_GET['sort'] ?? 'newest'),
    'page'     => max(1, (int)($_GET['page'] ?? 1)),
    'per_page' => 20,
];

$db = new Database();
$result = $db->getBids($filters);
$bids = $result['data'];
$total = $result['total'];
$per_page = $result['per_page'];
$page = $result['page'];
$total_pages = ceil($total / $per_page);

$stats = $db->getStats();
$keywords = $db->getKeywords();
$source_counts = $db->getSourceCounts($filters);
$tag_counts = $db->getTagCounts($filters);

// 엑셀 다운로드
if (isset($_GET['export'])) {
    require_once 'export.php';
    $selected = isset($_GET['ids']) ? array_filter(array_map('intval', explode(',', $_GET['ids'] ?? ''))) : [];
    exportExcel($db, $selected, $filters);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>입찰 공고 모니터링</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#f5f7fa; --surface:#fff; --surface2:#f8f9fc; --border:#e4e8ef; --accent:#2563eb;
  --accent-hover:#1d4ed8; --accent-light:#eff6ff; --green:#059669; --orange:#d97706; --red:#dc2626;
  --text:#1e293b; --text-muted:#64748b; --text-dim:#94a3b8;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Noto Sans KR',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}

.header{background:var(--surface);border-bottom:1px solid var(--border);padding:0 24px;display:flex;align-items:center;justify-content:space-between;height:56px;position:sticky;top:0;z-index:100}
.logo{display:flex;align-items:center;gap:10px;font-weight:700;font-size:15px}
.logo-icon{width:32px;height:32px;background:linear-gradient(135deg,var(--accent),#3b82f6);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;color:#fff}
.btn-header{padding:8px 16px;border-radius:6px;font-size:13px;text-decoration:none;color:var(--accent);border:1px solid var(--accent);background:#fff}
.btn-header:hover{background:var(--accent-light)}

.main-layout{display:flex;max-width:1400px;margin:0 auto;padding:20px;gap:24px;align-items:flex-start}
.sidebar{width:260px;flex-shrink:0;position:sticky;top:76px}
.content{flex:1;min-width:0}

.filter-panel{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.filter-title{font-size:13px;font-weight:600;color:var(--text-muted);margin-bottom:12px}
.search-wrap{position:relative;margin-bottom:20px}
.search-input{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:10px 14px 10px 36px;font-size:14px;outline:none;transition:border-color .2s}
.search-input:focus{border-color:var(--accent)}
.search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-dim);cursor:pointer}
.suggest-list{position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--border);border-radius:8px;margin-top:4px;max-height:200px;overflow-y:auto;z-index:50;box-shadow:0 4px 12px rgba(0,0,0,.1)}
.suggest-item{display:block;padding:10px 14px;font-size:13px;color:var(--text);text-decoration:none;border-bottom:1px solid var(--border)}
.suggest-item:last-child{border-bottom:none}
.suggest-item:hover{background:var(--accent-light)}

.filter-group{margin-bottom:20px}
.filter-group:last-child{margin-bottom:0}
.filter-label{font-size:11px;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px}
.filter-options{display:flex;flex-direction:column;gap:6px}
.filter-link{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border-radius:6px;font-size:13px;color:var(--text-muted);text-decoration:none;transition:all .15s}
.filter-link:hover{background:var(--surface2);color:var(--text)}
.filter-link.active{background:var(--accent-light);color:var(--accent);font-weight:500}
.filter-link .count{font-size:11px;color:var(--text-dim);background:var(--surface2);padding:2px 8px;border-radius:10px}

.multi-select{position:relative}
.multi-select-toggle{width:100%;padding:8px 12px;border-radius:6px;border:1px solid var(--border);background:var(--surface);font-size:13px;display:flex;align-items:center;justify-content:space-between;cursor:pointer}
.multi-select-toggle span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.multi-select-menu{position:absolute;top:110%;left:0;right:0;background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:60;max-height:220px;overflow-y:auto;padding:6px 0;display:none}
.multi-select-item{display:flex;align-items:center;justify-content:space-between;padding:6px 12px;font-size:13px;cursor:pointer;gap:6px}
.multi-select-item input{margin-right:6px}
.multi-select-item:hover{background:var(--accent-light)}

.tag-btns{display:flex;flex-wrap:wrap;gap:6px}
.tag-btn{display:inline-block;padding:6px 12px;border-radius:6px;font-size:12px;background:var(--surface2);color:var(--text-muted);border:1px solid var(--border);text-decoration:none;transition:all .15s}
.tag-btn:hover{background:var(--accent-light);color:var(--accent);border-color:var(--accent)}
.tag-btn.active{background:var(--accent);color:#fff;border-color:var(--accent)}

.period-select{width:100%;padding:8px 12px;border-radius:6px;border:1px solid var(--border);font-size:13px;background:var(--surface);margin-bottom:8px}
.btn-filter{padding:8px 16px;border-radius:6px;font-size:13px;background:var(--accent);color:#fff;border:none;cursor:pointer;width:100%;margin-top:12px}
.btn-filter:hover{background:var(--accent-hover)}

.toolbar{display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.toolbar .search-wrap{flex:1;min-width:200px;max-width:320px;margin:0}
.filter-select{padding:8px 14px;border-radius:6px;border:1px solid var(--border);font-size:13px;background:var(--surface)}
.btn{padding:8px 16px;border-radius:6px;font-size:13px;border:none;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:var(--accent);color:#fff}
.btn-excel{background:#fff;border:1px solid var(--green);color:var(--green)}

.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.bid-table{width:100%;border-collapse:collapse;font-size:13px}
.bid-table thead{background:var(--surface2)}
.bid-table th,.bid-table td{padding:10px 12px;border-bottom:1px solid var(--border);text-align:left}
.bid-table th{font-size:12px;color:var(--text-muted);font-weight:600}
.bid-table tbody tr:hover{background:var(--accent-light)}
.bid-table .title-cell a{color:var(--text);text-decoration:none}
.bid-table .title-cell a:hover{color:var(--accent)}
.bid-table .source-badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:500}
.source-nara{background:#eff6ff;color:var(--accent)}
.source-kstartup{background:#ecfdf5;color:var(--green)}
.source-smes24{background:#f5f3ff;color:#6d28d9}
.source-bizinfo{background:#fef3c7;color:#b45309}
.source-smtech{background:#fff7ed;color:var(--orange)}
.source-iitp{background:#fef2f2;color:var(--red)}
.deadline{font-size:12px}
.deadline.urgent{color:var(--red);font-weight:600}
.deadline.soon{color:var(--orange)}
.deadline.normal{color:var(--text-muted)}
.kw-badge{display:inline-block;font-size:11px;padding:2px 6px;margin:1px;background:var(--surface2);border-radius:4px;color:var(--text-muted)}

.pagination{display:flex;justify-content:space-between;align-items:center;margin-top:24px;flex-wrap:wrap;gap:12px}
.page-buttons{display:flex;gap:6px}
.page-btn{width:36px;height:36px;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text-muted);font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:center;text-decoration:none}
.page-btn:hover{border-color:var(--accent);color:var(--accent)}
.page-btn.active{background:var(--accent);border-color:var(--accent);color:#fff}

.empty-state{text-align:center;padding:80px 20px;color:var(--text-muted)}
.stats-bar{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:16px}
.stat-label{font-size:12px;color:var(--text-muted);margin-bottom:4px}
.stat-value{font-size:20px;font-weight:700}

@media(max-width:900px){
  .main-layout{flex-direction:column}
  .sidebar{width:100%;position:static}
  .card-grid{grid-template-columns:1fr}
  .stats-bar{grid-template-columns:repeat(2,1fr)}
}
</style>
</head>
<body>

<header class="header">
  <div class="logo">
    <div class="logo-icon">📋</div>
    입찰 공고 모니터
  </div>
  <div style="display:flex;align-items:center;gap:16px">
    <span style="font-size:12px;color:var(--text-muted)">최종 수집: <?= htmlspecialchars($stats['last_fetch'] ?? '-') ?></span>
    <a href="fetch.php?manual=1" class="btn-header">🔄 수집 실행</a>
  </div>
</header>

<div class="main-layout">
  <!-- 좌측 필터 패널 -->
  <aside class="sidebar">
    <div class="filter-panel">
      <form method="GET" action="index.php" id="filterForm">
        <input type="hidden" name="source" value="<?= htmlspecialchars($filters['source']) ?>">
        <input type="hidden" name="deadline" value="<?= htmlspecialchars($filters['deadline']) ?>">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($filters['sort']) ?>">
        <input type="hidden" name="tags" id="tagsInput" value="<?= htmlspecialchars(implode(',', $filters['tags'])) ?>">

        <div class="filter-title">공고명 · 기관명 검색</div>
        <div class="search-wrap" style="margin-bottom:20px">
          <span class="search-icon">🔍</span>
          <input type="text" name="search" class="search-input" id="searchInput" value="<?= htmlspecialchars($filters['search']) ?>" placeholder="검색어 입력..." autocomplete="off">
          <div class="suggest-list" id="suggestList" style="display:none"></div>
        </div>

        <?php
          $selectedSources = $filters['sources'];
          if (!$selectedSources && $filters['source'] !== '') {
            $selectedSources = [$filters['source']];
          }
        ?>
        <div class="filter-group">
          <div class="filter-label">사이트명</div>
          <div class="multi-select" id="sourceMulti">
            <button type="button" class="multi-select-toggle" id="sourceToggle">
              <span>
                <?php if (empty($selectedSources)): ?>
                  전체 사이트
                <?php else: ?>
                  <?= htmlspecialchars(implode(', ', $selectedSources)) ?>
                <?php endif; ?>
              </span>
              <span style="font-size:11px;color:var(--text-dim)">▼</span>
            </button>
            <div class="multi-select-menu" id="sourceMenu">
              <label class="multi-select-item">
                <span>
                  <input type="checkbox" value="__all" <?= empty($selectedSources) ? 'checked' : '' ?>> 전체
                </span>
                <span class="count"><?= (int)($source_counts['전체'] ?? 0) ?></span>
              </label>
              <?php foreach ($source_counts as $name => $cnt): if ($name === '전체') continue; ?>
              <label class="multi-select-item">
                <span>
                  <input type="checkbox" class="source-option" value="<?= htmlspecialchars($name) ?>" <?= in_array($name, $selectedSources, true) ? 'checked' : '' ?>>
                  <?= htmlspecialchars($name) ?>
                </span>
                <span class="count"><?= (int)$cnt ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <input type="hidden" name="sources" id="sourcesInput" value="<?= htmlspecialchars(implode(',', $selectedSources)) ?>">
        </div>

        <div class="filter-group">
          <div class="filter-label">마감일</div>
          <div class="filter-options">
            <a href="?<?= http_build_query(array_merge($_GET, ['deadline'=>'','page'=>1])) ?>" class="filter-link <?= $filters['deadline']===''?'active':'' ?>">전체</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['deadline'=>'3','page'=>1])) ?>" class="filter-link <?= $filters['deadline']==='3'?'active':'' ?>">3일 이내</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['deadline'=>'7','page'=>1])) ?>" class="filter-link <?= $filters['deadline']==='7'?'active':'' ?>">7일 이내</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['deadline'=>'30','page'=>1])) ?>" class="filter-link <?= $filters['deadline']==='30'?'active':'' ?>">1개월 이내</a>
          </div>
        </div>

        <div class="filter-group">
          <div class="filter-label">태그 검색</div>
          <div class="multi-select" id="tagMulti">
            <button type="button" class="multi-select-toggle" id="tagToggle">
              <span>
                <?php if (empty($filters['tags'])): ?>
                  전체
                <?php else:
                  $selectedNames = array_map(function ($id) use ($keywords) {
                    foreach ($keywords as $k) { if ((int)$k['id'] === (int)$id) return $k['keyword']; }
                    return '';
                  }, $filters['tags']);
                  $selectedNames = array_filter($selectedNames);
                  ?>
                  <?= htmlspecialchars(implode(', ', $selectedNames)) ?>
                <?php endif; ?>
              </span>
              <span style="font-size:11px;color:var(--text-dim)">▼</span>
            </button>
            <div class="multi-select-menu" id="tagMenu">
              <?php
              $tagCountMap = [];
              foreach ($tag_counts['keywords'] ?? [] as $kw) {
                $tagCountMap[(int)$kw['id']] = (int)$kw['cnt'];
              }
              ?>
              <label class="multi-select-item">
                <span>
                  <input type="checkbox" value="__all" id="tagAll" <?= empty($filters['tags']) ? 'checked' : '' ?>> 전체
                </span>
                <span class="count"><?= (int)($tag_counts['total'] ?? 0) ?></span>
              </label>
              <?php foreach ($keywords as $kw):
                $kid = (int)$kw['id'];
                $cnt = $tagCountMap[$kid] ?? 0;
                $name = $kw['keyword'];
              ?>
              <label class="multi-select-item">
                <span>
                  <input type="checkbox" class="tag-option" value="<?= $kid ?>" data-keyword="<?= htmlspecialchars($name) ?>" <?= in_array($kid, $filters['tags'], true) ? 'checked' : '' ?>>
                  <?= htmlspecialchars($name) ?>
                </span>
                <span class="count"><?= $cnt ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <p style="font-size:11px;color:var(--text-dim);margin-top:6px">여러 태그 선택 시 해당 키워드 중 하나라도 포함된 공고가 표시됩니다.</p>
        </div>

        <button type="submit" class="btn-filter">필터 적용</button>
      </form>

      <div class="filter-group" style="margin-top:20px">
        <div class="filter-label">키워드 관리</div>
        <form method="POST" action="keyword_add.php" style="display:flex;gap:6px;margin-bottom:8px">
          <input type="text" name="keyword" placeholder="키워드 입력 후 + 클릭" style="flex:1;padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px" required>
          <button type="submit" class="btn btn-primary" style="padding:6px 12px">+</button>
        </form>
        <?php foreach ($keywords as $kw): ?>
        <div style="display:inline-flex;align-items:center;gap:4px;margin:2px">
          <span style="font-size:12px"><?= htmlspecialchars($kw['keyword']) ?></span>
          <a href="keyword_delete.php?id=<?= $kw['id'] ?>" onclick="return confirm('삭제?')" style="color:var(--text-dim);font-size:14px">×</a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </aside>

  <!-- 우측 콘텐츠 -->
  <main class="content">
    <div class="stats-bar">
      <div class="stat-card"><div class="stat-label">오늘 수집</div><div class="stat-value" style="color:var(--accent)"><?= (int)$stats['today_total'] ?></div></div>
      <div class="stat-card"><div class="stat-label">키워드 매칭</div><div class="stat-value" style="color:var(--green)"><?= (int)$stats['keyword_match'] ?></div></div>
      <div class="stat-card"><div class="stat-label">마감 3일 이내</div><div class="stat-value" style="color:var(--orange)"><?= (int)$stats['urgent'] ?></div></div>
      <div class="stat-card"><div class="stat-label">신규</div><div class="stat-value" style="color:var(--red)"><?= (int)$stats['new_today'] ?></div></div>
    </div>

    <form method="GET" action="index.php" id="listForm">
      <input type="hidden" name="search" value="<?= htmlspecialchars($filters['search']) ?>">
      <input type="hidden" name="source" value="<?= htmlspecialchars($filters['source']) ?>">
      <input type="hidden" name="deadline" value="<?= htmlspecialchars($filters['deadline']) ?>">
      <input type="hidden" name="tags" value="<?= htmlspecialchars(implode(',', $filters['tags'])) ?>">
      <div class="toolbar">
        <select name="sort" class="filter-select" onchange="this.form.submit()">
          <option value="newest" <?= $filters['sort']==='newest'?'selected':'' ?>>최신순</option>
          <option value="deadline" <?= $filters['sort']==='deadline'?'selected':'' ?>>마감일순</option>
          <option value="amount" <?= $filters['sort']==='amount'?'selected':'' ?>>금액순</option>
        </select>
        <button type="button" class="btn btn-excel" id="btnExport">엑셀 다운로드</button>
      </div>
    </form>

    <!-- 테이블 리스트 -->
    <div class="table-wrap">
      <?php if (empty($bids)): ?>
        <div class="empty-state">
          <div style="font-size:48px;margin-bottom:16px">📭</div>
          <div>검색 결과가 없습니다.</div>
        </div>
      <?php else: ?>
      <table class="bid-table">
        <thead>
          <tr>
            <th style="width:36px;text-align:center"><input type="checkbox" id="checkAll"></th>
            <th style="width:60px">순번</th>
            <th>공고명</th>
            <th style="width:120px">주관기관</th>
            <th style="width:100px">마감일</th>
            <th style="width:100px">사이트 명</th>
            <th style="width:180px">키워드</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($bids as $i => $bid):
          $rowNo = ($page - 1) * $per_page + $i + 1;
          $deadline_class = getDeadlineClass($bid['deadline_date'] ?? '');
          $source_class = getSourceClass($bid['source']);
          $linkUrl = $bid['url'] ?? '';
          // DB에 &amp; 형태로 저장된 URL이 있더라도 클릭 시 정상 이동하도록 복원
          $linkUrl = html_entity_decode((string)$linkUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
          if (($bid['source'] ?? '') === '기업마당' && $linkUrl !== '' && strpos($linkUrl, 'http') !== 0) {
            $linkUrl = 'https://www.bizinfo.go.kr' . (strpos($linkUrl, '/') === 0 ? $linkUrl : '/' . $linkUrl);
          }
          // 영등포구청 상세는 /www 하위인데, 과거 데이터에 /www 누락 URL이 있어 417이 발생함
          if (($bid['source'] ?? '') === '영등포구청' && $linkUrl !== '') {
            $linkUrl = preg_replace('#^https?://www\\.ydp\\.go\\.kr/selectBbsNttView\\.do#', 'https://www.ydp.go.kr/www/selectBbsNttView.do', $linkUrl);
          }
        ?>
          <tr>
            <td style="text-align:center"><input type="checkbox" class="row-check" value="<?= $bid['id'] ?>"></td>
            <td><?= $rowNo ?></td>
            <td class="title-cell">
              <a href="<?= htmlspecialchars($linkUrl) ?>" target="_blank" title="공고 상세 보기"><?= htmlspecialchars($bid['title']) ?></a>
            </td>
            <td><?= htmlspecialchars($bid['org_name'] ?? '-') ?></td>
            <td><span class="deadline <?= $deadline_class ?>"><?= htmlspecialchars($bid['deadline_date'] ?? '-') ?></span></td>
            <td><span class="source-badge <?= $source_class ?>"><?= htmlspecialchars($bid['source']) ?></span></td>
            <td>
              <?php if (!empty($bid['matched_keywords'])): ?>
                <?php foreach (explode(',', $bid['matched_keywords']) as $kw): ?>
                  <?php if (trim($kw)): ?><span class="kw-badge"><?= htmlspecialchars(trim($kw)) ?></span><?php endif; ?>
                <?php endforeach; ?>
              <?php else: ?>
                -
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
      <span style="font-size:13px;color:var(--text-muted)">총 <?= $total ?>건</span>
      <div class="page-buttons">
        <?php if ($page > 1): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>" class="page-btn">‹</a><?php endif; ?>
        <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?>
        <a href="?<?= http_build_query(array_merge($_GET,['page'=>$p])) ?>" class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>" class="page-btn">›</a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </main>
</div>

<script>
(function(){
  const STORAGE_KEY = 'bid_monitor_bookmarks';
  function getBookmarks(){try{return JSON.parse(localStorage.getItem(STORAGE_KEY)||'[]')}catch(e){return []}}
  function setBookmarks(arr){localStorage.setItem(STORAGE_KEY,JSON.stringify(arr))}
  function isBookmarked(id){return getBookmarks().includes(id)}

  const searchInput = document.getElementById('searchInput');
  const suggestList = document.getElementById('suggestList');
  const searchIcon  = document.querySelector('.search-icon');
  const sourceToggle = document.getElementById('sourceToggle');
  const sourceMenu   = document.getElementById('sourceMenu');
  const sourcesInput = document.getElementById('sourcesInput');
  let suggestTimer;

  function fetchSuggest(q){
    fetch('api_suggest.php?q='+encodeURIComponent(q))
      .then(r=>r.json())
      .then(data=>{
        if(!data.keywords||data.keywords.length===0){ suggestList.style.display='none'; return; }
        suggestList.innerHTML = data.keywords.map(k=>{
          const p = new URLSearchParams(window.location.search);
          p.set('search', searchInput.value.trim());
          let tags = (p.get('tags') || '').split(',').filter(Boolean);
          if (!tags.includes(String(k.id))) tags.push(String(k.id));
          p.set('tags', tags.join(','));
          p.delete('tag');
          p.set('page', '1');
          return '<a href="?'+p.toString()+'" class="suggest-item">'+escapeHtml(k.keyword)+'</a>';
        }).join('');
        suggestList.style.display='block';
      })
      .catch(()=>{ suggestList.style.display='none'; });
  }

  searchInput.addEventListener('input',function(){
    clearTimeout(suggestTimer);
    const q = this.value.trim();
    if(q.length<1){ suggestList.style.display='none'; return; }
    suggestTimer = setTimeout(()=>fetchSuggest(q), 200);
  });
  searchInput.addEventListener('focus',function(){
    if(this.value.trim().length>0) fetchSuggest(this.value.trim());
  });
  searchInput.addEventListener('blur',()=>setTimeout(()=>{ suggestList.style.display='none'; },150));

  function escapeHtml(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML}

  // 돋보기 아이콘 클릭 시 검색 실행
  if (searchIcon) {
    searchIcon.addEventListener('click', () => {
      const form = document.getElementById('filterForm');
      if (form) {
        form.submit();
      }
    });
  }

  // 사이트명 멀티 선택 드롭다운
  if (sourceToggle && sourceMenu && sourcesInput) {
    const allCheckbox = sourceMenu.querySelector('input[value="__all"]');
    const optionCheckboxes = Array.from(sourceMenu.querySelectorAll('.source-option'));

    function updateHiddenInput() {
      const selected = optionCheckboxes.filter(ch => ch.checked).map(ch => ch.value);
      if (allCheckbox) {
        allCheckbox.checked = selected.length === 0;
      }
      sourcesInput.value = selected.join(',');
      const labelSpan = sourceToggle.querySelector('span');
      if (labelSpan) {
        labelSpan.textContent = selected.length === 0 ? '전체 사이트' : selected.join(', ');
      }
    }

    sourceToggle.addEventListener('click', () => {
      const isOpen = sourceMenu.style.display === 'block';
      sourceMenu.style.display = isOpen ? 'none' : 'block';
    });

    if (allCheckbox) {
      allCheckbox.addEventListener('change', () => {
        if (allCheckbox.checked) {
          optionCheckboxes.forEach(ch => { ch.checked = false; });
        }
        updateHiddenInput();
      });
    }

    optionCheckboxes.forEach(ch => {
      ch.addEventListener('change', () => {
        if (allCheckbox && ch.checked) {
          allCheckbox.checked = false;
        }
        updateHiddenInput();
      });
    });

    document.addEventListener('click', (e) => {
      if (!sourceMenu.contains(e.target) && !sourceToggle.contains(e.target)) {
        sourceMenu.style.display = 'none';
      }
    });
  }

  // 태그 멀티 선택 드롭다운 (사이트명과 동일 패턴)
  const tagToggle = document.getElementById('tagToggle');
  const tagMenu = document.getElementById('tagMenu');
  const tagsInput = document.getElementById('tagsInput');
  if (tagToggle && tagMenu && tagsInput) {
    const tagAllCheckbox = document.getElementById('tagAll');
    const tagOptionCheckboxes = Array.from(document.querySelectorAll('.tag-option'));

    function updateTagHidden() {
      const selected = tagOptionCheckboxes.filter(ch => ch.checked).map(ch => ch.value);
      if (tagAllCheckbox) {
        tagAllCheckbox.checked = selected.length === 0;
      }
      tagsInput.value = selected.join(',');
      const labelSpan = tagToggle.querySelector('span');
      if (labelSpan) {
        if (selected.length === 0) {
          labelSpan.textContent = '전체';
        } else {
          labelSpan.textContent = selected.map(id => {
            const opt = tagOptionCheckboxes.find(ch => ch.value === id);
            return opt ? (opt.getAttribute('data-keyword') || id) : id;
          }).join(', ');
        }
      }
    }

    tagToggle.addEventListener('click', (e) => {
      e.preventDefault();
      const isOpen = tagMenu.style.display === 'block';
      tagMenu.style.display = isOpen ? 'none' : 'block';
    });

    if (tagAllCheckbox) {
      tagAllCheckbox.addEventListener('change', () => {
        if (tagAllCheckbox.checked) {
          tagOptionCheckboxes.forEach(ch => { ch.checked = false; });
        }
        updateTagHidden();
      });
    }

    tagOptionCheckboxes.forEach(ch => {
      ch.addEventListener('change', () => {
        if (tagAllCheckbox && ch.checked) {
          tagAllCheckbox.checked = false;
        }
        updateTagHidden();
      });
    });

    document.addEventListener('click', (e) => {
      if (!tagMenu.contains(e.target) && !tagToggle.contains(e.target)) {
        tagMenu.style.display = 'none';
      }
    });
  }

  // 체크박스 전체 선택
  const checkAll = document.getElementById('checkAll');
  const rowChecks = document.querySelectorAll('.row-check');
  if (checkAll && rowChecks.length) {
    checkAll.addEventListener('change', () => {
      rowChecks.forEach(ch => { ch.checked = checkAll.checked; });
    });
    rowChecks.forEach(ch => {
      ch.addEventListener('change', () => {
        if (!ch.checked) checkAll.checked = false;
      });
    });
  }

  // 엑셀 다운로드: 체크한 행만 / 안 했으면 필터된 목록 전체
  const btnExport = document.getElementById('btnExport');
  if (btnExport) {
    btnExport.addEventListener('click', () => {
      const checked = Array.from(document.querySelectorAll('.row-check:checked')).map(ch => ch.value);
      const url = new URL(window.location.href);
      url.searchParams.set('export', '1');
      if (checked.length) {
        url.searchParams.set('ids', checked.join(','));
      } else {
        url.searchParams.delete('ids');
      }
      window.location.href = url.toString();
    });
  }
})();
</script>
</body>
</html>

<?php
function getDeadlineClass(string $dateStr): string {
  if(!$dateStr) return 'normal';
  $diff = (strtotime($dateStr) - strtotime('today'))/86400;
  if($diff<=3) return 'urgent';
  if($diff<=7) return 'soon';
  return 'normal';
}
function getSourceClass(string $source): string {
  return match($source){
    '나라장터'=>'source-nara','K-스타트업'=>'source-kstartup','중소벤처24'=>'source-smes24','기업마당'=>'source-bizinfo',
    '중소기업기술정보진흥원'=>'source-smtech','IITP'=>'source-iitp',
    default=>'source-nara'
  };
}
