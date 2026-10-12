<?php
declare(strict_types=1);
require_once __DIR__ . '/app.php';
require_login();
$db = db();
$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$hotel = ['name'=>'','location'=>'','notes'=>'']; $rooms = [];
if ($id) {
    $s=$db->prepare('SELECT * FROM hotels WHERE id=?');
    $s->execute([$id]);
    $found=$s->fetch();
    if (!$found) { http_response_code(404); exit('Hotel not found.'); }
    $hotel=$found;
    $s=$db->prepare('SELECT * FROM rooms WHERE hotel_id=? ORDER BY id');
    $s->execute([$id]);
    $rooms=$s->fetchAll();
    foreach($rooms as &$room){
        $s=$db->prepare('SELECT * FROM room_images WHERE room_id=? ORDER BY id');
        $s->execute([$room['id']]);
        $room['images']=$s->fetchAll();
        $dates=json_decode((string)($room['available_dates']??'[]'),true);
        $room['selected_dates']=is_array($dates)?array_values(array_filter($dates,'is_string')):[];
        $room['public_link_active']=!empty($room['public_token'])&&!empty($room['public_expires_at'])&&strtotime($room['public_expires_at'].' UTC')>time();
    }
    unset($room);
}
$errors=[];
$isUploadXhr=$_SERVER['REQUEST_METHOD']==='POST'&&strtolower($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='xmlhttprequest';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 check_csrf();
 $action=$_POST['action']??'save';
 if ($action==='delete_hotel' && $id) {
     $db->beginTransaction();
     $s=$db->prepare('SELECT i.path FROM room_images i JOIN rooms r ON r.id=i.room_id WHERE r.hotel_id=?');
     $s->execute([$id]);
     foreach($s->fetchAll() as $im) delete_image_file($im['path']);
     $db->prepare('DELETE FROM hotels WHERE id=?')->execute([$id]);
     $db->commit();
     flash('Hotel deleted.');
     redirect('index.php');
 }
 if ($action==='delete_room' && $id) {
     $roomId=(int)($_POST['room_id']??0);
     $s=$db->prepare('SELECT path FROM room_images WHERE room_id=?');
     $s->execute([$roomId]);
     foreach($s->fetchAll() as $im) delete_image_file($im['path']);
     $db->prepare('DELETE FROM rooms WHERE id=? AND hotel_id=?')->execute([$roomId,$id]);
     flash('Room category deleted.');
     redirect('hotel.php?id='.$id);
 }
 if ($action==='delete_image' && $id) {
     $imageId=(int)($_POST['image_id']??0);
     $s=$db->prepare('SELECT i.path, i.room_id FROM room_images i JOIN rooms r ON r.id=i.room_id WHERE i.id=? AND r.hotel_id=?');
     $s->execute([$imageId,$id]);
     if($im=$s->fetch()){
         delete_image_file($im['path']);
         $db->prepare('DELETE FROM room_images WHERE id=?')->execute([$imageId]);
         if ($isUploadXhr) {
             header('Content-Type: application/json; charset=utf-8');
             echo json_encode(['ok'=>true, 'image_id'=>$imageId, 'room_id'=>(int)$im['room_id'], 'message'=>'Photo removed.']);
             exit;
         }
     }
     flash('Photo removed.');
     redirect('hotel.php?id='.$id);
 }
 if ($action==='upload_room_photos' && $id) {
     $roomId=(int)($_POST['room_id']??0);
     $s=$db->prepare('SELECT id, name FROM rooms WHERE id=? AND hotel_id=?');
     $s->execute([$roomId,$id]);
     $roomCheck=$s->fetch();
     if(!$roomCheck) {
         http_response_code(404);
         header('Content-Type: application/json; charset=utf-8');
         echo json_encode(['ok'=>false, 'errors'=>['Room category not found.']]);
         exit;
     }
     $files=$_FILES['images']??null;
     $uploaded=[];
     $fileErrors=[];
     if($files && is_array($files['name'])) {
         $count=count(array_filter($files['name'],fn($n)=>$n!==''));
         if($count>20) {
             $fileErrors[]='Upload up to 20 photos per batch.';
         } else {
             for($i=0; $i<count($files['name']); $i++) {
                 if($files['name'][$i]==='') continue;
                 try {
                     $path=save_webp([
                         'name'=>$files['name'][$i],
                         'type'=>$files['type'][$i],
                         'tmp_name'=>$files['tmp_name'][$i],
                         'error'=>$files['error'][$i],
                         'size'=>$files['size'][$i]
                     ]);
                     if($path) {
                         $db->prepare('INSERT INTO room_images(room_id,path) VALUES(?,?)')->execute([$roomId,$path]);
                         $newId=(int)$db->lastInsertId();
                         $uploaded[]=['id'=>$newId, 'path'=>$path, 'room_name'=>$roomCheck['name']];
                     }
                 } catch(Throwable $e) {
                     $fileErrors[]=$e->getMessage();
                 }
             }
         }
     }
     if(!$uploaded && $fileErrors) {
         http_response_code(422);
         header('Content-Type: application/json; charset=utf-8');
         echo json_encode(['ok'=>false, 'errors'=>array_values(array_unique($fileErrors))]);
         exit;
     }
     if($isUploadXhr) {
         header('Content-Type: application/json; charset=utf-8');
         echo json_encode([
             'ok'=>true,
             'room_id'=>$roomId,
             'uploaded'=>$uploaded,
             'errors'=>array_values(array_unique($fileErrors)),
             'message'=>count($uploaded) . ' photo' . (count($uploaded)===1?'':'s') . ' uploaded successfully.'
         ]);
         exit;
     }
     flash(count($uploaded) . ' photo(s) uploaded.');
     redirect('hotel.php?id='.$id);
 }
 if ($action==='share_room' && $id) {
     $roomId=(int)($_POST['room_id']??0);
     $shareMode=$_POST['share_mode']??'enable';
     if($shareMode==='disable'){
         $db->prepare('UPDATE rooms SET public_token=NULL,public_expires_at=NULL WHERE id=? AND hotel_id=?')->execute([$roomId,$id]);
         flash('Public link disabled.');
     } else {
         $db->prepare('UPDATE rooms SET public_token=?,public_expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY) WHERE id=? AND hotel_id=?')->execute([bin2hex(random_bytes(12)),$roomId,$id]);
         flash('Public link created. It will expire in 7 days.');
     }
     redirect('hotel.php?id='.$id);
 }
 if ($action==='save') {
     $hotel['name']=trim($_POST['name']??''); $hotel['location']=trim($_POST['location']??''); $hotel['notes']=trim($_POST['notes']??'');
     if ($hotel['name']==='') $errors[]='Hotel name is required.';
     if ($hotel['location']==='') $errors[]='Location is required.';
     if (!$errors) {
         $values=[$hotel['name'],$hotel['location'],$hotel['notes']?:null];
         if($id){
             $db->prepare('UPDATE hotels SET name=?,location=?,notes=? WHERE id=?')->execute([...$values,$id]);
         } else {
             $db->prepare('INSERT INTO hotels(name,location,notes) VALUES(?,?,?)')->execute($values);
             $id=(int)$db->lastInsertId();
         }
         flash('Hotel details saved.');
         redirect('hotel.php?id='.$id);
     }
 }
 if ($action==='save_room' && $id) {
     $roomId=(int)($_POST['room_id']??0);
     $name=trim($_POST['room_name']??'');
     $description=trim($_POST['description']??'');
     $br=trim($_POST['room_buying_rate']??'');
     $cr=trim($_POST['room_commission_rate']??'');
     $sr=trim($_POST['room_selling_rate']??'');
     $datesRaw=(string)($_POST['available_dates']??'[]');
     $selectedDates=json_decode($datesRaw,true);
     if(!$name) $errors[]='Enter a room category name.';
     if(!is_array($selectedDates)) { $selectedDates=[]; $errors[]='Date selection was invalid. Please choose the dates again.'; }
     if(count($selectedDates)>1000) $errors[]='Choose up to 1,000 dates for a room category.';
     foreach($selectedDates as $selectedDate) {
         $dateObject=is_string($selectedDate)?DateTime::createFromFormat('!Y-m-d',$selectedDate):false;
         if(!$dateObject||$dateObject->format('Y-m-d')!==$selectedDate) {
             $errors[]='One or more selected dates are invalid.';
             break;
         }
     }
     $selectedDates=array_values(array_unique(array_filter($selectedDates,'is_string')));
     sort($selectedDates);
     $datesJson=json_encode($selectedDates, JSON_THROW_ON_ERROR);
     if(!$errors) {
         try {
             if($roomId){
                 $db->prepare('UPDATE rooms SET name=?,description=?,buying_rate=?,commission_rate=?,selling_rate=?,available_dates=? WHERE id=? AND hotel_id=?')->execute([$name,$description?:null,$br!==''?$br:null,$cr!==''?$cr:null,$sr!==''?$sr:null,$datesJson,$roomId,$id]);
             } else {
                 $db->prepare('INSERT INTO rooms(hotel_id,name,description,buying_rate,commission_rate,selling_rate,available_dates) VALUES(?,?,?,?,?,?,?)')->execute([$id,$name,$description?:null,$br!==''?$br:null,$cr!==''?$cr:null,$sr!==''?$sr:null,$datesJson]);
                 $roomId=(int)$db->lastInsertId();
             }
             $files=$_FILES['images']??null;
             $uploadedList=[];
             if($files && is_array($files['name'])) {
                 $count=count(array_filter($files['name'],fn($n)=>$n!==''));
                 if($count>20) throw new RuntimeException('Upload up to 20 photos per room category at a time.');
                 for($i=0;$i<count($files['name']);$i++) {
                     if($files['name'][$i]==='') continue;
                     $path=save_webp([
                         'name'=>$files['name'][$i],
                         'type'=>$files['type'][$i],
                         'tmp_name'=>$files['tmp_name'][$i],
                         'error'=>$files['error'][$i],
                         'size'=>$files['size'][$i]
                     ]);
                     if($path) {
                         $db->prepare('INSERT INTO room_images(room_id,path) VALUES(?,?)')->execute([$roomId,$path]);
                         $uploadedList[]=['id'=>(int)$db->lastInsertId(), 'path'=>$path];
                     }
                 }
             }
             flash('Room category saved.');
             if($isUploadXhr){
                 header('Content-Type: application/json; charset=utf-8');
                 echo json_encode(['ok'=>true, 'room_id'=>$roomId, 'uploaded'=>$uploadedList, 'redirect'=>'hotel.php?id='.$id], JSON_THROW_ON_ERROR);
                 exit;
             }
             redirect('hotel.php?id='.$id);
         } catch(Throwable $e) {
             $errors[]=$e instanceof RuntimeException ? $e->getMessage() : 'Could not save the room category. Please try again.';
         }
     }
 }
}
if($isUploadXhr){
    http_response_code($errors?422:400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'errors'=>array_values(array_unique($errors?:['The request could not be completed. Please try again.']))],JSON_THROW_ON_ERROR);
    exit;
}
$notice=flash();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#26715d">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<title><?= $id?'Edit hotel':'Add hotel' ?> · Staybook</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="apple-touch-icon" href="icons/icon-192.png">
<link rel="stylesheet" href="style.css">
<script defer src="photo-preview.js"></script>
<script defer src="app.js"></script>
<script defer src="pwa.js"></script>
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php">
    <span class="brand-mark">S</span>
    <span>staybook<small>HOTEL DIRECTORY</small></span>
  </a>
  <div class="topbar-right">
    <a class="text-link" href="users.php">Users</a>
    <a class="button button-light" href="logout.php">Log out</a>
    <a class="text-link" href="index.php">← All hotels</a>
  </div>
</header>

<main class="detail-shell">
  <div class="breadcrumb">
    <a href="index.php">Hotels</a><span>/</span><b><?= $id?h($hotel['name']):'New hotel' ?></b>
  </div>

  <div class="detail-heading">
    <div>
      <div class="eyebrow"><?= $id?'PROPERTY DETAILS':'NEW PROPERTY' ?></div>
      <h1><?= $id?h($hotel['name']):'Add a hotel' ?></h1>
      <p><?= $id?'Manage room categories, rates, dates and photos.':'Create a hotel, then add its room categories and rates.' ?></p>
    </div>
    <?php if($id): ?>
      <form method="post" action="hotel.php?id=<?=$id?>" onsubmit="return confirm('Delete this hotel and all its room categories and photos?')">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="delete_hotel">
        <input type="hidden" name="id" value="<?=$id?>">
        <button class="button button-danger-outline" type="submit">Delete hotel</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if($notice): ?><div class="notice success"><?=h($notice['message'])?></div><?php endif; ?>
  <?php if($errors): ?>
    <div class="notice error">
      <b>Please check the following:</b>
      <ul><?php foreach(array_unique($errors) as $error): ?><li><?=h($error)?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" action="hotel.php<?=$id ? '?id=' . $id : ''?>" class="panel hotel-form">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?=$id?>">
    <div class="panel-heading">
      <div><h2>Hotel details</h2><p>Basic hotel name, location and notes</p></div>
      <span class="step">01</span>
    </div>
    <div class="form-grid">
      <label class="span-2">Hotel name <em>*</em>
        <input name="name" required maxlength="160" placeholder="e.g. The Grand Riverside" value="<?=h($hotel['name'])?>">
      </label>
      <label class="span-2">Location <em>*</em>
        <input name="location" required maxlength="255" placeholder="City, country or full address" value="<?=h($hotel['location'])?>">
      </label>
      <label class="span-2">Internal notes
        <textarea name="notes" rows="3" placeholder="Anything your team should know about this property"><?=h((string)$hotel['notes'])?></textarea>
      </label>
    </div>
    <div class="form-actions">
      <span><em>*</em> Required fields</span>
      <button class="button button-dark" type="submit">Save hotel <span>→</span></button>
    </div>
  </form>

  <?php if($id): ?>
  <section class="rooms-section">
    <div class="section-title">
      <div>
        <div class="eyebrow">ROOM INVENTORY</div>
        <h2>Room categories <span class="count"><?=count($rooms)?></span></h2>
        <p>Add room specific rates, available dates, and reference photos.</p>
      </div>
    </div>

    <div class="room-list">
      <?php foreach($rooms as $room): ?>
      <article class="room-card" id="room-card-<?=$room['id']?>">
        <div class="room-card-heading">
          <div>
            <span class="room-tag">ROOM CATEGORY</span>
            <h3><?=h($room['name'])?></h3>
            <?php if($room['description']): ?><p><?=h($room['description'])?></p><?php endif; ?>
          </div>
          <button class="button button-light" type="button" data-edit-room="<?=$room['id']?>">Edit rates / dates</button>
          <form method="post" action="hotel.php?id=<?=$id?>" class="share-control">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="share_room">
            <input type="hidden" name="id" value="<?=$id?>">
            <input type="hidden" name="room_id" value="<?=$room['id']?>">
            <?php if($room['public_link_active']): ?>
              <input type="hidden" name="share_mode" value="disable">
              <a class="button button-light" href="public-room.php?token=<?=h($room['public_token'])?>" target="_blank" rel="noopener">Open public page ↗</a>
              <button class="button button-danger-outline" type="submit">Disable link</button>
              <small class="share-expiry">Expires <?=h(date('M j, g:i a',strtotime($room['public_expires_at'].' UTC')))?></small>
            <?php else: ?>
              <input type="hidden" name="share_mode" value="enable">
              <button class="button button-outline" type="submit"><?=!empty($room['public_token'])?'Renew expired link':'Create public link'?></button>
            <?php endif; ?>
          </form>
        </div>

        <div class="rate-strip">
          <?php foreach(['buying_rate'=>'Buying rate','commission_rate'=>'Commission','selling_rate'=>'Selling rate'] as $key=>$label): ?>
            <div>
              <small><?=$label?></small>
              <b><?= $room[$key]!==null && $room[$key]!=='' ? h((string)$room[$key]) : '—' ?></b>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if($room['selected_dates']): ?>
          <div class="room-dates">
            <small>Selected dates</small>
            <?php foreach($room['selected_dates'] as $selectedDate): ?>
              <span><?=date('M j, Y',strtotime($selectedDate))?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Room Photos Grid -->
        <div class="photo-grid <?=empty($room['images'])?'hidden':''?>" data-room-photo-grid="<?=$room['id']?>">
          <?php foreach($room['images'] as $image): ?>
            <div class="photo" data-image-id="<?=$image['id']?>">
              <img src="<?=h($image['path'])?>" alt="<?=h($room['name'])?> photo" loading="lazy" class="photo-img" data-preview-image="<?=h($image['path'])?>" data-preview-alt="<?=h($room['name'])?> photo" role="button" tabindex="0" title="Click to view full photo">
              <form method="post" action="hotel.php?id=<?=$id?>" class="delete-photo-form" data-delete-image-form data-image-id="<?=$image['id']?>" onsubmit="return confirm('Remove this photo?')">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="delete_image">
                <input type="hidden" name="id" value="<?=$id?>">
                <input type="hidden" name="image_id" value="<?=$image['id']?>">
                <button type="submit" title="Remove photo" aria-label="Remove photo">×</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Room Edit Form -->
        <form method="post" action="hotel.php?id=<?=$id?>" class="room-edit-form <?=isset($_POST['room_id'])&&(int)$_POST['room_id']===(int)$room['id']?'':'hidden'?>" id="room-<?=$room['id']?>" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="save_room">
          <input type="hidden" name="id" value="<?=$id?>">
          <input type="hidden" name="room_id" value="<?=$room['id']?>">
          
          <div class="form-grid">
            <label>Category name <em>*</em>
              <input name="room_name" required value="<?=h($room['name'])?>">
            </label>
            <label>Description
              <input name="description" value="<?=h($room['description']??'')?>" placeholder="Bed type, occupancy, view…">
            </label>
            <label>Buying rate
              <div class="input-prefix"><span>৳</span><input type="text" name="room_buying_rate" value="<?=h((string)$room['buying_rate'])?>" maxlength="120" placeholder="e.g. 100 - 150"></div>
            </label>
            <label>Commission
              <div class="input-suffix"><input type="text" name="room_commission_rate" value="<?=h((string)$room['commission_rate'])?>" maxlength="120" placeholder="e.g. 100 - 150"><span>%</span></div>
            </label>
            <label>Selling rate
              <div class="input-prefix"><span>৳</span><input type="text" name="room_selling_rate" value="<?=h((string)$room['selling_rate'])?>" maxlength="120" placeholder="e.g. 100 - 150"></div>
            </label>

            <div class="date-selection span-2">
              <label>Select available dates
                <input type="date" data-date-input>
              </label>
              <button class="button button-outline" type="button" data-add-date>Add date</button>
              <div class="date-chip-list" data-date-list></div>
              <input type="hidden" name="available_dates" data-selected-dates value="<?=h(json_encode(isset($_POST['room_id'])&&(int)$_POST['room_id']===(int)$room['id']&&isset($selectedDates)?$selectedDates:$room['selected_dates'], JSON_THROW_ON_ERROR))?>">
              <small>Pick a date in the calendar, then add more dates as needed.</small>
            </div>

            <!-- Modern Drag & Drop Uploader Component -->
            <div class="span-2 upload-zone-container" data-room-id="<?=$room['id']?>">
              <div class="upload-zone-header">
                <label class="upload-zone-label">
                  <span>Add Room Photos</span>
                  <small class="muted">JPG, PNG, WebP · Auto-optimized before upload</small>
                </label>
              </div>

              <div class="upload-dropzone" tabindex="0" role="button" aria-label="Drop room photos here or click to browse">
                <input type="file" name="images[]" class="upload-file-input" accept="image/jpeg,image/png,image/webp,image/*" multiple aria-hidden="true" tabindex="-1">
                <div class="upload-dropzone-inner">
                  <div class="upload-dropzone-icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                      <polyline points="17 8 12 3 7 8"/>
                      <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                  </div>
                  <div class="upload-dropzone-text">
                    <strong>Choose photos</strong> or drag &amp; drop here
                    <small>High-resolution camera photos are resized to 2000px and converted to WebP</small>
                  </div>
                  <button type="button" class="button button-light upload-browse-trigger">Browse files</button>
                </div>
              </div>

              <!-- Staging Queue -->
              <div class="upload-staging-tray hidden" data-staging-tray>
                <div class="upload-staging-bar">
                  <span class="staging-heading">Selected photos (<span data-staging-counter>0</span>)</span>
                  <div class="staging-actions">
                    <button type="button" class="staging-action-btn" data-staging-clear>Clear all</button>
                    <button type="button" class="button button-dark button-sm staging-upload-btn" data-stage-upload>Upload photos now ⚡</button>
                  </div>
                </div>
                <div class="upload-staging-grid" data-staging-grid></div>
              </div>

              <!-- Progress Panel -->
              <div class="upload-progress-panel hidden" data-progress-panel aria-live="polite">
                <div class="upload-progress-header">
                  <div class="upload-progress-title-wrap">
                    <div class="upload-spinner" data-progress-spinner></div>
                    <strong data-progress-status>Uploading photos…</strong>
                  </div>
                  <span class="upload-progress-rate" data-progress-pct>0%</span>
                </div>
                <div class="upload-progress-bar-wrap" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                  <div class="upload-progress-fill" data-progress-fill></div>
                </div>
                <div class="upload-progress-foot">
                  <span class="upload-progress-summary" data-progress-summary></span>
                  <button type="button" class="upload-cancel-btn" data-progress-cancel>Cancel</button>
                </div>
              </div>

              <div class="upload-toast-alert hidden" data-upload-alert></div>
            </div>
          </div>

          <div class="room-form-actions">
            <button class="button button-danger-outline" type="submit" form="delete-room-<?=$room['id']?>">Delete category</button>
            <button class="button button-dark" type="submit">Save category <span>→</span></button>
          </div>
        </form>

        <form id="delete-room-<?=$room['id']?>" method="post" action="hotel.php?id=<?=$id?>" onsubmit="return confirm('Delete this room category and its photos?')">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="delete_room">
          <input type="hidden" name="id" value="<?=$id?>">
          <input type="hidden" name="room_id" value="<?=$room['id']?>">
        </form>
      </article>
      <?php endforeach; ?>
    </div>

    <button class="button button-outline add-room" type="button" data-new-room>＋ Add room category</button>

    <!-- New Room Category Form -->
    <form method="post" action="hotel.php?id=<?=$id?>" class="panel new-room-form <?=($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='save_room'&&empty($_POST['room_id']))?'':'hidden'?>" id="new-room" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="save_room">
      <input type="hidden" name="id" value="<?=$id?>">
      
      <div class="panel-heading">
        <div>
          <h2>New room category</h2>
          <p>Room details, pricing, available dates and photos</p>
        </div>
        <button class="icon-button" type="button" data-cancel-room aria-label="Close">×</button>
      </div>

      <div class="form-grid">
        <label>Category name <em>*</em>
          <input name="room_name" required placeholder="e.g. Deluxe King" value="<?=($_POST['action']??'')==='save_room'&&empty($_POST['room_id'])?h($_POST['room_name']??''):''?>">
        </label>
        <label>Description
          <input name="description" placeholder="Bed type, occupancy, view…" value="<?=($_POST['action']??'')==='save_room'&&empty($_POST['room_id'])?h($_POST['description']??''):''?>">
        </label>
        <label>Buying rate
          <div class="input-prefix"><span>৳</span><input type="text" name="room_buying_rate" placeholder="e.g. 100 - 150" maxlength="120" value="<?=($_POST['action']??'')==='save_room'&&empty($_POST['room_id'])?h($_POST['room_buying_rate']??''):''?>"></div>
        </label>
        <label>Commission
          <div class="input-suffix"><input type="text" name="room_commission_rate" placeholder="e.g. 100 - 150" maxlength="120" value="<?=($_POST['action']??'')==='save_room'&&empty($_POST['room_id'])?h($_POST['room_commission_rate']??''):''?>"><span>%</span></div>
        </label>
        <label>Selling rate
          <div class="input-prefix"><span>৳</span><input type="text" name="room_selling_rate" placeholder="e.g. 100 - 150" maxlength="120" value="<?=($_POST['action']??'')==='save_room'&&empty($_POST['room_id'])?h($_POST['room_selling_rate']??''):''?>"></div>
        </label>

        <div class="date-selection span-2">
          <label>Select available dates
            <input type="date" data-date-input>
          </label>
          <button class="button button-outline" type="button" data-add-date>Add date</button>
          <div class="date-chip-list" data-date-list></div>
          <input type="hidden" name="available_dates" data-selected-dates value="<?=h(($_POST['action']??'')==='save_room'&&empty($_POST['room_id'])?($_POST['available_dates']??'[]'):'[]')?>">
          <small>Pick a date in the calendar, then add more dates as needed.</small>
        </div>

        <!-- Modern Drag & Drop Uploader Component for New Room -->
        <div class="span-2 upload-zone-container" data-room-id="">
          <div class="upload-zone-header">
            <label class="upload-zone-label">
              <span>Room Photos</span>
              <small class="muted">JPG, PNG, WebP · Auto-optimized before upload</small>
            </label>
          </div>

          <div class="upload-dropzone" tabindex="0" role="button" aria-label="Drop room photos here or click to browse">
            <input type="file" name="images[]" class="upload-file-input" accept="image/jpeg,image/png,image/webp,image/*" multiple aria-hidden="true" tabindex="-1">
            <div class="upload-dropzone-inner">
              <div class="upload-dropzone-icon">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                  <polyline points="17 8 12 3 7 8"/>
                  <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
              </div>
              <div class="upload-dropzone-text">
                <strong>Choose photos</strong> or drag &amp; drop here
                <small>High-resolution camera photos are resized to 2000px and converted to WebP</small>
              </div>
              <button type="button" class="button button-light upload-browse-trigger">Browse files</button>
            </div>
          </div>

          <!-- Staging Queue -->
          <div class="upload-staging-tray hidden" data-staging-tray>
            <div class="upload-staging-bar">
              <span class="staging-heading">Selected photos (<span data-staging-counter>0</span>)</span>
              <div class="staging-actions">
                <button type="button" class="staging-action-btn" data-staging-clear>Clear all</button>
              </div>
            </div>
            <div class="upload-staging-grid" data-staging-grid></div>
          </div>

          <!-- Progress Panel -->
          <div class="upload-progress-panel hidden" data-progress-panel aria-live="polite">
            <div class="upload-progress-header">
              <div class="upload-progress-title-wrap">
                <div class="upload-spinner" data-progress-spinner></div>
                <strong data-progress-status>Uploading photos…</strong>
              </div>
              <span class="upload-progress-rate" data-progress-pct>0%</span>
            </div>
            <div class="upload-progress-bar-wrap" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
              <div class="upload-progress-fill" data-progress-fill></div>
            </div>
            <div class="upload-progress-foot">
              <span class="upload-progress-summary" data-progress-summary></span>
              <button type="button" class="upload-cancel-btn" data-progress-cancel>Cancel</button>
            </div>
          </div>

          <div class="upload-toast-alert hidden" data-upload-alert></div>
        </div>
      </div>

      <div class="form-actions">
        <span>Photos are resized to 2000px and compressed automatically.</span>
        <button class="button button-dark" type="submit">Save category <span>→</span></button>
      </div>
    </form>
  </section>
  <?php endif; ?>

  <footer>Staybook <span>·</span> Internal hotel information</footer>
</main>

<!-- Lightbox Modal Dialog for Room Photos -->
<dialog class="photo-preview" aria-label="Image preview">
  <button class="photo-preview-close" type="button" aria-label="Close image preview">×</button>
  <img class="photo-preview-image" alt="">
  <p class="photo-preview-caption"></p>
</dialog>

<?php mobile_app_nav('hotel'); ?>
</body>
</html>

