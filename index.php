<?php
// =============================================
// 입찰 공고 모니터링 시스템 - index.php
// 좌측 필터 패널 + 우측 카드형 리스트
// =============================================

require_once 'config.php';
require_once 'db.php';
require_once 'api_fetch.php';

$filters = [
    'search'   => trim($_GET['search'] ?? ''),
    'source'   => trim($_GET['source'] ?? ''),
    'deadline' => trim($_GET['deadline'] ?? ''),
    'tag'      => isset($_GET['tag']) ? (int)$_GET['tag'] : 0,
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
.search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-dim)}
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

.card-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px}
.bid-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:20px;transition:all .2s;position:relative}
.bid-card:hover{border-color:var(--accent);box-shadow:0 4px 12px rgba(37,99,235,.1)}
.bid-card-header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px}
.bid-card-title{font-size:15px;font-weight:600;line-height:1.4;color:var(--text);text-decoration:none;flex:1;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.bid-card-title:hover{color:var(--accent)}
.bookmark-btn{flex-shrink:0;width:32px;height:32px;border:none;background:transparent;cursor:pointer;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:18px;opacity:.5;transition:opacity .2s}
.bookmark-btn:hover{opacity:1}
.bookmark-btn.active{opacity:1}
.bookmark-btn.active::before{content:'★'}
.bookmark-btn:not(.active)::before{content:'☆'}

.bid-card-meta{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
.source-badge{padding:4px 10px;border-radius:6px;font-size:11px;font-weight:500}
.source-nara{background:#eff6ff;color:var(--accent)}
.source-kstartup{background:#ecfdf5;color:var(--green)}
.source-smtech{background:#fff7ed;color:var(--orange)}
.source-iitp{background:#fef2f2;color:var(--red)}

.bid-card-org{font-size:13px;color:var(--text-muted);margin-bottom:8px}
.bid-card-keywords{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:12px}
.kw-tag{font-size:11px;padding:2px 8px;background:var(--surface2);border-radius:4px;color:var(--text-muted)}
.bid-card-footer{display:flex;justify-content:space-between;align-items:center;padding-top:12px;border-top:1px solid var(--border);font-size:12px}
.bid-card-amount{font-weight:600;color:var(--text)}
.deadline{font-size:12px}
.deadline.urgent{color:var(--red);font-weight:600}
.deadline.soon{color:var(--orange)}
.deadline.normal{color:var(--text-muted)}
.new-badge{display:inline-block;background:#ecfdf5;color:var(--green);font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;margin-left:6px}

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
        <input type="hidden" name="tag" value="<?= $filters['tag'] ?>">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($filters['sort']) ?>">

        <div class="filter-title">공고명 · 기관명 검색</div>
        <div class="search-wrap" style="margin-bottom:20px">
          <span class="search-icon">🔍</span>
          <input type="text" name="search" class="search-input" id="searchInput" value="<?= htmlspecialchars($filters['search']) ?>" placeholder="검색어 입력..." autocomplete="off">
          <div class="suggest-list" id="suggestList" style="display:none"></div>
        </div>

        <div class="filter-group">
          <div class="filter-label">출처</div>
          <div class="filter-options">
            <a href="?<?= http_build_query(array_merge($_GET, ['source'=>'','page'=>1])) ?>" class="filter-link <?= $filters['source']===''?'active':'' ?>">
              전체 <span class="count"><?= $source_counts['전체'] ?? 0 ?></span>
            </a>
            <?php foreach (['나라장터','K-스타트업','IITP','중소기업기술정보진흥원'] as $s): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['source'=>$s,'page'=>1])) ?>" class="filter-link <?= $filters['source']===$s?'active':'' ?>">
              <?= htmlspecialchars($s) ?> <span class="count"><?= $source_counts[$s] ?? 0 ?></span>
            </a>
            <?php endforeach; ?>
          </div>
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
          <div class="tag-btns">
            <a href="?<?= http_build_query(array_merge($_GET, ['tag'=>'','page'=>1])) ?>" class="tag-btn <?= !$filters['tag']?'active':'' ?>">전체</a>
            <?php foreach ($keywords as $kw): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['tag'=>$kw['id'],'page'=>1])) ?>" class="tag-btn <?= $filters['tag']==$kw['id']?'active':'' ?>"><?= htmlspecialchars($kw['keyword']) ?></a>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="filter-group">
          <div class="filter-label">키워드 관리</div>
          <form method="POST" action="keyword_add.php" style="display:flex;gap:6px;margin-bottom:8px">
            <input type="text" name="keyword" placeholder="추가" style="flex:1;padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
            <button type="submit" class="btn btn-primary" style="padding:6px 12px">+</button>
          </form>
          <?php foreach ($keywords as $kw): ?>
          <div style="display:inline-flex;align-items:center;gap:4px;margin:2px">
            <span style="font-size:12px"><?= htmlspecialchars($kw['keyword']) ?></span>
            <a href="keyword_delete.php?id=<?= $kw['id'] ?>" onclick="return confirm('삭제?')" style="color:var(--text-dim);font-size:14px">×</a>
          </div>
          <?php endforeach; ?>
        </div>

        <button type="submit" class="btn-filter">필터 적용</button>
      </form>
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
      <input type="hidden" name="tag" value="<?= $filters['tag'] ?>">
      <div class="toolbar">
        <select name="sort" class="filter-select" onchange="this.form.submit()">
          <option value="newest" <?= $filters['sort']==='newest'?'selected':'' ?>>최신순</option>
          <option value="deadline" <?= $filters['sort']==='deadline'?'selected':'' ?>>마감일순</option>
          <option value="amount" <?= $filters['sort']==='amount'?'selected':'' ?>>금액순</option>
        </select>
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'all'])) ?>" class="btn btn-excel">엑셀 다운로드</a>
      </div>
    </form>

    <!-- 카드 리스트 -->
    <div class="card-grid" id="cardGrid">
      <?php if (empty($bids)): ?>
        <div class="empty-state" style="grid-column:1/-1">
          <div style="font-size:48px;margin-bottom:16px">📭</div>
          <div>검색 결과가 없습니다.</div>
        </div>
      <?php else: ?>
        <?php foreach ($bids as $bid):
          $deadline_class = getDeadlineClass($bid['deadline_date'] ?? '');
          $source_class = getSourceClass($bid['source']);
          $is_new = !empty($bid['created_at']) && strtotime($bid['created_at']) >= strtotime('today');
        ?>
        <article class="bid-card" data-bid-id="<?= $bid['id'] ?>">
          <div class="bid-card-header">
            <a href="<?= htmlspecialchars($bid['url']) ?>" target="_blank" class="bid-card-title" title="<?= htmlspecialchars($bid['title']) ?>">
              <?= htmlspecialchars($bid['title']) ?><?php if($is_new): ?><span class="new-badge">NEW</span><?php endif; ?>
            </a>
            <button type="button" class="bookmark-btn" data-bid-id="<?= $bid['id'] ?>" aria-label="스크랩" title="관심 공고"></button>
          </div>
          <div class="bid-card-meta">
            <span class="source-badge <?= $source_class ?>"><?= htmlspecialchars($bid['source']) ?></span>
          </div>
          <div class="bid-card-org"><?= htmlspecialchars($bid['org_name'] ?? '-') ?></div>
          <?php if (!empty($bid['matched_keywords'])): ?>
          <div class="bid-card-keywords">
            <?php foreach (explode(',', $bid['matched_keywords']) as $kw): ?>
              <?php if(trim($kw)): ?><span class="kw-tag"><?= htmlspecialchars(trim($kw)) ?></span><?php endif; ?>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <div class="bid-card-footer">
            <span class="bid-card-amount"><?= htmlspecialchars($bid['budget'] ?? '-') ?></span>
            <span class="deadline <?= $deadline_class ?>"><?= htmlspecialchars($bid['deadline_date'] ?? '-') ?></span>
          </div>
        </article>
        <?php endforeach; ?>
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

  document.querySelectorAll('.bookmark-btn').forEach(btn=>{
    const id = parseInt(btn.dataset.bidId,10);
    if(isBookmarked(id)) btn.classList.add('active');
    btn.addEventListener('click',function(){
      let arr = getBookmarks();
      if(arr.includes(id)) arr = arr.filter(x=>x!==id);
      else arr.push(id);
      setBookmarks(arr);
      btn.classList.toggle('active');
    });
  });

  const searchInput = document.getElementById('searchInput');
  const suggestList = document.getElementById('suggestList');
  let suggestTimer;

  function fetchSuggest(q){
    fetch('api_suggest.php?q='+encodeURIComponent(q))
      .then(r=>r.json())
      .then(data=>{
        if(!data.keywords||data.keywords.length===0){ suggestList.style.display='none'; return; }
        suggestList.innerHTML = data.keywords.map(k=>{
          const p = new URLSearchParams(window.location.search);
          p.set('search', searchInput.value.trim());
          p.set('tag', k.id);
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
    '나라장터'=>'source-nara','K-스타트업'=>'source-kstartup',
    '중소기업기술정보진흥원'=>'source-smtech','IITP'=>'source-iitp',
    default=>'source-nara'
  };
}
