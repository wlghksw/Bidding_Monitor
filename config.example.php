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

// ── 중소벤처24 공고정보 API ──
// 발급: https://www.data.go.kr → "중소벤처24 공고정보" 검색 또는 smes.go.kr API 신청
define('SMES24_API_KEY', 'YOUR_SMES24_API_KEY');  // GET 호출 시 url encoding하여 전달
define('SMES24_API_URL', 'https://www.smes.go.kr/fnct/apiReqst/extPblancInfo');
define('SMES24_DAYS', 30);  // strDt~endDt 조회 기간(일)

// ── 기업마당(비즈인포) 지원사업 RSS ──
define('BIZINFO_API_KEY', 'YOUR_BIZINFO_API_KEY');
define('BIZINFO_API_URL', 'https://www.bizinfo.go.kr/uss/rss/bizinfoApi.do');

// ── 수집 설정 ──
define('FETCH_INTERVAL_HOURS', 24);   // 수집 주기 (시간)
define('G2B_DAYS', 90);               // 나라장터 조회 기간 (일)
define('MAX_PAGES_PER_SOURCE', 50);   // 나라장터 최대 페이지 수
define('KSTARTUP_MAX_PAGES', 150);    // K-스타트업 최대 페이지

// ── 타임존 ──
date_default_timezone_set('Asia/Seoul');
