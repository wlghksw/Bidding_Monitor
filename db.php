<?php
// =============================================
// db.php - 데이터베이스 클래스
// =============================================

class Database {
    private PDO $pdo;

    public function __construct() {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    // ── 공고 목록 조회 ──
    public function getBids(array $filters): array {
        $search   = trim($filters['search'] ?? '');
        $source   = trim($filters['source'] ?? '');
        $deadline = trim($filters['deadline'] ?? '');
        $tagId    = isset($filters['tag']) ? (int)$filters['tag'] : 0;
        $from     = trim($filters['from'] ?? '');
        $to       = trim($filters['to'] ?? '');
        $sort     = trim($filters['sort'] ?? 'newest');
        $page     = max(1, (int)($filters['page'] ?? 1));
        $perPage  = (int)($filters['per_page'] ?? 20);

        $where   = ['1=1'];
        $params  = [];

        if ($search) {
            $where[] = '(b.title LIKE :search OR b.org_name LIKE :search)';
            $params[':search'] = "%{$search}%";
        }
        if ($source) {
            $where[] = 'b.source = :source';
            $params[':source'] = $source;
        }
        if ($deadline) {
            $where[] = 'b.deadline_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)';
            $params[':days'] = (int)$deadline;
        }
        if ($tagId) {
            $where[] = 'EXISTS (SELECT 1 FROM bid_keywords bk2 WHERE bk2.bid_id = b.id AND bk2.keyword_id = :tag_id)';
            $params[':tag_id'] = $tagId;
        }
        if ($from) {
            $where[] = '(b.deadline_date >= :date_from OR b.notice_date >= :date_from)';
            $params[':date_from'] = $from;
        }
        if ($to) {
            $where[] = '(b.deadline_date <= :date_to OR b.notice_date <= :date_to OR (b.deadline_date IS NULL AND b.notice_date IS NULL))';
            $params[':date_to'] = $to;
        }

        $orderBy = match($sort) {
            'deadline' => 'b.deadline_date ASC',
            'amount'   => 'b.budget_raw DESC',
            default    => 'b.fetched_at DESC',
        };

        $whereStr = implode(' AND ', $where);
        $offset   = ($page - 1) * $perPage;

        $countSql = "SELECT COUNT(*) FROM bids b WHERE {$whereStr}";
        $stmt     = $this->pdo->prepare($countSql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();

        $sql = "SELECT b.*, GROUP_CONCAT(k.keyword) AS matched_keywords
                FROM bids b
                LEFT JOIN bid_keywords bk ON bk.bid_id = b.id
                LEFT JOIN keywords k ON k.id = bk.keyword_id
                WHERE {$whereStr}
                GROUP BY b.id
                ORDER BY {$orderBy}
                LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll();

        return array_merge(compact('data', 'total', 'page'), ['per_page' => $perPage]);
    }

    // ── 출처별 건수 (탭용) ──
    public function getSourceCounts(array $filters = []): array {
        $where = ['1=1'];
        $params = [];
        $search = trim($filters['search'] ?? '');
        $deadline = trim($filters['deadline'] ?? '');
        $tagId = isset($filters['tag']) ? (int)$filters['tag'] : 0;

        if ($search) { $where[] = '(b.title LIKE :s OR b.org_name LIKE :s)'; $params[':s'] = "%{$search}%"; }
        if ($deadline) { $where[] = 'b.deadline_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :d DAY)'; $params[':d'] = (int)$deadline; }
        if ($tagId) { $where[] = 'EXISTS (SELECT 1 FROM bid_keywords bk2 WHERE bk2.bid_id = b.id AND bk2.keyword_id = :tid)'; $params[':tid'] = $tagId; }

        $whereStr = implode(' AND ', $where);
        $sql = "SELECT b.source, COUNT(*) AS cnt FROM bids b WHERE {$whereStr} GROUP BY b.source";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $out = ['전체' => 0];
        foreach ($rows as $r) {
            $out[$r['source']] = (int)$r['cnt'];
            $out['전체'] += (int)$r['cnt'];
        }
        return $out;
    }

    // ── 키워드 자동완성/추천 ──
    public function getKeywordSuggestions(string $q, int $limit = 10): array {
        if (strlen($q) < 1) return $this->getKeywords();
        $stmt = $this->pdo->prepare("SELECT id, keyword FROM keywords WHERE keyword LIKE :q ORDER BY keyword LIMIT " . (int)$limit);
        $stmt->execute([':q' => "%{$q}%"]);
        return $stmt->fetchAll();
    }

    // ── 통계 ──
    public function getStats(): array {
        return [
            'today_total'   => $this->pdo->query("SELECT COUNT(*) FROM bids WHERE DATE(fetched_at) = CURDATE()")->fetchColumn(),
            'keyword_match' => $this->pdo->query("SELECT COUNT(DISTINCT bid_id) FROM bid_keywords")->fetchColumn(),
            'urgent'        => $this->pdo->query("SELECT COUNT(*) FROM bids WHERE deadline_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 3 DAY)")->fetchColumn(),
            'new_today'     => $this->pdo->query("SELECT COUNT(*) FROM bids WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
            'last_fetch'    => $this->pdo->query("SELECT MAX(fetched_at) FROM bids")->fetchColumn() ?? '-',
        ];
    }

    // ── 키워드 목록 ──
    public function getKeywords(): array {
        return $this->pdo->query("SELECT id, keyword FROM keywords ORDER BY id")->fetchAll();
    }

    // ── 키워드 추가 ──
    public function addKeyword(string $keyword): void {
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO keywords (keyword) VALUES (:keyword)");
        $stmt->execute([':keyword' => $keyword]);
    }

    // ── 키워드 삭제 ──
    public function deleteKeyword(int $id): void {
        $stmt = $this->pdo->prepare("DELETE FROM keywords WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    // ── 공고 저장 (중복 방지) ──
    public function saveBid(array $bid): int {
        $sql = "INSERT INTO bids (title, url, source, org_name, budget, budget_raw, deadline_date, fetched_at)
                VALUES (:title, :url, :source, :org_name, :budget, :budget_raw, :deadline_date, NOW())
                ON DUPLICATE KEY UPDATE fetched_at = NOW()";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bid);
        return (int) $this->pdo->lastInsertId();
    }

    // ── 키워드 매칭 저장 ──
    public function saveBidKeywords(int $bidId, array $keywordIds): void {
        $this->pdo->prepare("DELETE FROM bid_keywords WHERE bid_id = ?")->execute([$bidId]);
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO bid_keywords (bid_id, keyword_id) VALUES (?, ?)");
        foreach ($keywordIds as $kId) {
            $stmt->execute([$bidId, $kId]);
        }
    }

    // ── 엑셀용 전체/선택 조회 ──
    public function getBidsForExport(array $ids = [], array $filters = []): array {
        $where  = ['1=1'];
        $params = [];
        $search = trim($filters['search'] ?? '');
        $source = trim($filters['source'] ?? '');
        $deadline = trim($filters['deadline'] ?? '');
        $tagId = isset($filters['tag']) ? (int)$filters['tag'] : 0;

        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $where[] = "b.id IN ({$placeholders})";
            $params = array_merge($params, $ids);
        }
        if ($search) { $where[] = '(b.title LIKE ? OR b.org_name LIKE ?)'; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }
        if ($source) { $where[] = 'b.source = ?'; $params[] = $source; }
        if ($deadline) { $where[] = 'b.deadline_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)'; $params[] = (int)$deadline; }
        if ($tagId) { $where[] = 'EXISTS (SELECT 1 FROM bid_keywords bk2 WHERE bk2.bid_id = b.id AND bk2.keyword_id = ?)'; $params[] = $tagId; }

        $sql = "SELECT b.title, b.url, b.source, b.org_name, b.budget, b.deadline_date,
                       b.fetched_at, GROUP_CONCAT(k.keyword) AS matched_keywords
                FROM bids b
                LEFT JOIN bid_keywords bk ON bk.bid_id = b.id
                LEFT JOIN keywords k ON k.id = bk.keyword_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY b.id ORDER BY b.fetched_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getPdo(): PDO { return $this->pdo; }
}
