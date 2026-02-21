<?php
// =============================================
// config.example.php - 설정 예시 (config.php로 복사 후 수정)
// cp config.example.php config.php
// =============================================

// ── 데이터베이스 ──
define('DB_HOST', 'localhost');
define('DB_NAME', 'bid_monitor');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_CHARSET', 'utf8mb4');

// ── 나라장터 (G2B) OpenAPI ──
// 발급: https://www.data.go.kr → "입찰공고정보서비스" 검색
define('G2B_API_KEY', 'YOUR_G2B_API_KEY');
define('G2B_API_URL', 'https://apis.data.go.kr/1230000/BidPublicInfoService04/getBidPblancListInfoServc');

// ── K-스타트업 OpenAPI ──
// 발급: https://www.data.go.kr → "창업지원사업공고" 검색
define('KSTARTUP_API_KEY', 'YOUR_KSTARTUP_API_KEY');
define('KSTARTUP_API_URL', 'https://apis.data.go.kr/B552735/kisedKstartupService/getAnnouncList');

// ── 중소기업기술정보진흥원 (SMTECH) OpenAPI ──
// 발급: https://www.data.go.kr → "중소기업기술개발지원사업공고" 검색
define('SMTECH_API_KEY', 'YOUR_SMTECH_API_KEY');
define('SMTECH_API_URL', 'https://apis.data.go.kr/B090041/openapi/service/SbizAnnouncService/getAnnouncList');

// ── IITP (크롤링) ──
define('IITP_URL', 'https://www.iitp.kr/kr/1/business/businessOpportunity/list.it');

// ── 수집 설정 ──
define('FETCH_INTERVAL_HOURS', 24);   // 수집 주기 (시간)
define('MAX_PAGES_PER_SOURCE', 5);    // 소스별 최대 페이지 수

// ── 타임존 ──
date_default_timezone_set('Asia/Seoul');
