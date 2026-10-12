<?php
declare(strict_types=1);
require_once __DIR__ . '/app.php';
require_login();
$db = db();
$q = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$rate = trim((string)($_GET['rate'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));
$dateError = '';
if ($date !== '') { $d = DateTime::createFromFormat('!Y-m-d', $date); if (!$d || $d->format('Y-m-d') !== $date) { $dateError = 'Choose a valid date.'; $date = ''; } }

$hotelSql = 'SELECT h.id,h.name,h.location FROM hotels h';
$hotelWhere = []; $hotelParams = [];
if ($q !== '') { $hotelWhere[] = '(h.name LIKE ? OR h.location LIKE ?)'; $hotelParams[] = "%$q%"; $hotelParams[] = "%$q%"; }
$roomFilters = []; $roomFilterParams = [];
if ($category !== '') { $roomFilters[] = 'fr.name LIKE ?'; $roomFilterParams[] = "%$category%"; }
if ($rate !== '') { $roomFilters[] = '(fr.buying_rate LIKE ? OR fr.commission_rate LIKE ? OR fr.selling_rate LIKE ?)'; array_push($roomFilterParams, "%$rate%", "%$rate%", "%$rate%"); }
if ($date !== '') { $roomFilters[] = 'JSON_CONTAINS(fr.available_dates, JSON_QUOTE(?))'; $roomFilterParams[] = $date; }
if ($roomFilters) { $hotelWhere[] = 'EXISTS (SELECT 1 FROM rooms fr WHERE fr.hotel_id=h.id AND ' . implode(' AND ', $roomFilters) . ')'; array_push($hotelParams, ...$roomFilterParams); }
if ($hotelWhere) $hotelSql .= ' WHERE ' . implode(' AND ', $hotelWhere);
$hotelSql .= ' ORDER BY h.name';
$stmt = $db->prepare($hotelSql); $stmt->execute($hotelParams); $hotels = $stmt->fetchAll();

$roomsByHotel = [];
if ($hotels) {
    $hotelIds = array_map(static fn($h) => (int)$h['id'], $hotels);
    $roomWhere = ['fr.hotel_id IN (' . implode(',', array_fill(0, count($hotelIds), '?')) . ')'];
    $roomParams = $hotelIds;
    $displayFilters = [];
    if ($category !== '') { $displayFilters[] = 'fr.name LIKE ?'; $roomParams[] = "%$category%"; }
    if ($rate !== '') { $displayFilters[] = '(fr.buying_rate LIKE ? OR fr.commission_rate LIKE ? OR fr.selling_rate LIKE ?)'; array_push($roomParams, "%$rate%", "%$rate%", "%$rate%"); }
    if ($date !== '') { $displayFilters[] = 'JSON_CONTAINS(fr.available_dates, JSON_QUOTE(?))'; $roomParams[] = $date; }
    if ($displayFilters) $roomWhere[] = implode(' AND ', $displayFilters);
    $roomStmt = $db->prepare('SELECT fr.id,fr.hotel_id,fr.name,fr.buying_rate,fr.commission_rate,fr.selling_rate,fr.available_dates FROM rooms fr WHERE ' . implode(' AND ', $roomWhere) . ' ORDER BY fr.name');
    $roomStmt->execute($roomParams);
    $roomRows = $roomStmt->fetchAll();
    if ($roomRows) {
        foreach ($roomRows as $room) { $dates=json_decode((string)($room['available_dates']??'[]'),true); $room['selected_dates']=is_array($dates)?array_values(array_filter($dates,'is_string')):[]; $roomsByHotel[(int)$room['hotel_id']][] = $room; }
    }
}
$hasSearch = $q !== '' || $category !== '' || $rate !== '' || $date !== '' || $dateError !== '';
$notice = flash();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#26715d"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><title>Staybook · Room rates</title><link rel="manifest" href="manifest.webmanifest"><link rel="apple-touch-icon" href="icons/icon-192.png"><link rel="stylesheet" href="style.css?v=6"><script defer src="pwa.js?v=4"></script></head><body>
<header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">S</span><span>staybook<small>ROOM RATE DIRECTORY</small></span></a><div class="topbar-right"><a class="text-link" href="users.php">Users</a><span class="secure"><i></i><?=h(auth_user()['email'])?></span><a class="button button-light" href="logout.php">Log out</a><a class="button button-dark" href="hotel.php">＋ Add hotel</a></div></header>
<main class="shell dashboard-shell"><section class="intro"><div><div class="eyebrow">HOTEL PARTNERS</div><h1>Find the right room,<br>at the right rate.</h1><p class="lede">All room categories, rates and available dates in one place.</p></div><div class="intro-note"><span class="note-icon">⌕</span><span>Search by category,<br>date and rate</span></div></section>
<?php if($notice): ?><div class="notice success"><?=h($notice['message'])?></div><?php endif; ?><?php if($dateError): ?><div class="notice error"><?=h($dateError)?></div><?php endif; ?>
<form class="directory-search" method="get"><label>Hotel or location<input type="search" name="q" placeholder="Hotel name or city" value="<?=h($q)?>"></label><label>Room category<input type="search" name="category" placeholder="e.g. Deluxe King" value="<?=h($category)?>"></label><label>Available on<input type="date" name="date" value="<?=h($date)?>"></label><label>Rate contains<input type="search" name="rate" placeholder="e.g. 100 - 150 or 15%" value="<?=h($rate)?>"></label><div class="search-actions"><button class="button button-dark" type="submit">⌕ Find rooms</button><?php if($hasSearch): ?><a class="clear-search" href="index.php">Clear</a><?php endif; ?></div><p class="search-hint">Fill one field or combine them to narrow the results.</p></form>
<section class="results-heading"><div><div class="eyebrow"><?= $hasSearch?'MATCHING ROOMS':'ROOM DIRECTORY' ?></div><h2><?=count($hotels)?> hotel<?=count($hotels)===1?'':'s'?> <span>·</span> <?php $roomTotal=0; foreach($hotels as $hrow) $roomTotal+=count($roomsByHotel[(int)$hrow['id']]??[]); echo $roomTotal; ?> room categor<?= $roomTotal===1?'y':'ies' ?></h2></div><a class="button button-outline" href="hotel.php">＋ Add hotel</a></section>
<?php if(!$hotels): ?><section class="empty"><div class="empty-icon">⌕</div><h3><?=$hasSearch?'No matching rooms':'No hotels added yet'?></h3><p><?=$hasSearch?'Try changing or clearing one of the filters.':'Add a hotel and room categories to build your directory.'?></p><?php if(!$hasSearch): ?><a class="button button-dark" href="hotel.php">＋ Add first hotel</a><?php endif; ?></section>
<?php else: ?><div class="results-list"><?php foreach($hotels as $hotel): ?><section class="hotel-results"><header class="hotel-results-heading"><div><span class="hotel-symbol">⌂</span><div><h3><?=h($hotel['name'])?></h3><p><?=h($hotel['location'])?></p></div></div><a href="hotel.php?id=<?=(int)$hotel['id']?>" class="button button-light">Manage hotel <span>→</span></a></header><?php if(!empty($roomsByHotel[(int)$hotel['id']])): ?><div class="room-results"><?php foreach($roomsByHotel[(int)$hotel['id']] as $room): ?><article class="room-result"><div class="room-result-name"><span class="room-dot"></span><div><b><?=h($room['name'])?></b><?php if($room['selected_dates']): ?><small><?php foreach($room['selected_dates'] as $i=>$selectedDate): ?><?= $i?' · ':'' ?><?=date('M j, Y',strtotime($selectedDate))?><?php endforeach; ?></small><?php elseif($hasSearch && $date): ?><small class="muted">No dates selected</small><?php endif; ?></div></div><div class="room-result-rates"><?php foreach(['buying_rate'=>'Buy','commission_rate'=>'Commission','selling_rate'=>'Sell'] as $key=>$label): ?><div><small><?=$label?></small><b><?=($room[$key]!==null && $room[$key]!=='')?h((string)$room[$key]):'—'?></b></div><?php endforeach; ?></div></article><?php endforeach; ?></div><?php else: ?><p class="no-room-results">No room categories match this search.</p><?php endif; ?></section><?php endforeach; ?></div><?php endif; ?>
<footer>Staybook <span>·</span> Internal hotel information</footer></main><?php mobile_app_nav('home'); ?></body></html>
