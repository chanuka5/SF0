<?php
session_start();
// Check for and create the 'uploads' directory if it doesn't exist
if (!is_dir('uploads')) {
    mkdir('uploads', 0777, true);
}
// DATABASE CONNECTION
$conn = new mysqli("localhost", "root", "", "oceanstore");
if ($conn->connect_error) die("Database connection failed: " . $conn->connect_error);

// FIREBASE CONFIG
$firebaseConfig = 'const firebaseConfig = {
  apiKey: "AIzaSyAncQxKYQWdnXvjsKUZhaKaJefNQl7Ijj4",
  authDomain: "oceanstore-aff4e.firebaseapp.com",
  projectId: "oceanstore-aff4e",
  storageBucket: "oceanstore-aff4e.firebasestorage.app",
  messagingSenderId: "712396650568",
  appId: "1:712396650568:web:77dd7692c11c3f3e42407d"
};';

// --- AUTH LOGIC ---
if (isset($_POST['logout'])) { session_destroy(); header("Location: ?"); exit; }

$loggedIn  = isset($_SESSION['email']);
$userEmail = $loggedIn ? $_SESSION['email'] : '';
// NOTE: $userRole is fetched from session, defaulting to 'user'. 
// It can also be 'seller', 'admin', or 'owner'. The 'pending_seller' role is removed.
$userRole  = $loggedIn ? ($_SESSION['role'] ?? 'user') : '';
// The owner can be defined by a hardcoded email
if ($userEmail === 'chanukamindiw2006@gmail.com') $userRole = 'owner';

// --- PERMISSION HELPERS ---
function isAdminOrOwner() { global $userRole; return in_array($userRole, ['admin','owner']); }
function isSellerOrAbove() { global $userRole; return in_array($userRole, ['seller','admin','owner']); }

// --- FILE UPLOAD HANDLER ---
function uploadFile($file, $prefix = 'slider_') {
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $targetDir = "uploads/";
    
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $fileName = $prefix . time() . '.' . $fileExt;
    $targetFile = $targetDir . $fileName;

    // Basic security check (only allow image types if it's a slider/product image)
    if ($prefix === 'slider_' || $prefix === 'slider1_' || $prefix === 'slider2_' || $prefix === 'product_') {
        $check = @getimagesize($file["tmp_name"]);
        if($check === false) return false;
    }
    
    if (move_uploaded_file($file["tmp_name"], $targetFile)) return $targetFile;
    return false;
}

// --- BACKEND ACTIONS ---

// 1. Save User (Registration)
if (isset($_POST['saveuser'])) {
    global $conn;
    $e = $conn->real_escape_string($_POST['email']);
    $n = $conn->real_escape_string($_POST['name']);
    $uid = "USR" . substr(md5($e), 0, 8);
    $role = ($e === 'chanukamindiw2006@gmail.com') ? 'owner' : 'user';
    $conn->query("INSERT IGNORE INTO users (email,name,role,verified,user_id) VALUES ('$e','$n','$role',1,'$uid')");
    exit;
}

// 2. Admin: Update Site Settings (Slider Uploads & Colors)
if (isset($_POST['update_site_settings']) && isAdminOrOwner()) {
    global $conn, $settings;
    $msg = $conn->real_escape_string($_POST['welcome_msg']);
    $color = $conn->real_escape_string($_POST['site_color']);

    // Handle Slider 1 Upload
    if (!empty($_FILES['slider_1']['name'])) {
        $path = uploadFile($_FILES['slider_1'], 'slider1_');
        if ($path) $conn->query("REPLACE INTO site_settings (setting_key, setting_value) VALUES ('slider_1', '$path')");
    }
    
    // Handle Slider 2 Upload
    if (!empty($_FILES['slider_2']['name'])) {
        $path = uploadFile($_FILES['slider_2'], 'slider2_');
        if ($path) $conn->query("REPLACE INTO site_settings (setting_key, setting_value) VALUES ('slider_2', '$path')");
    }
    
    // Update text and color
    $conn->query("REPLACE INTO site_settings (setting_key, setting_value) VALUES ('welcome_msg', '$msg')");
    $conn->query("REPLACE INTO site_settings (setting_key, setting_value) VALUES ('site_color', '$color')");

    header("Location: ?page=admin&msg=Settings Updated"); exit;
}

// 3. Admin: Add Category
if (isset($_POST['add_category']) && isAdminOrOwner()) {
    global $conn;
    $name = $conn->real_escape_string($_POST['cat_name']);
    $parent = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : "NULL";
    $conn->query("INSERT INTO categories (name, parent_id) VALUES ('$name', $parent)");
    header("Location: ?page=admin&msg=Category Added"); exit;
}

// 4. Admin: Manage Categories (Delete)
if (isset($_GET['action']) && $_GET['action'] == 'delete_category' && isAdminOrOwner()) {
    global $conn;
    $cat_id = (int)($_GET['id'] ?? 0);
    
    // 1. Check if any products are linked
    $checkRes = $conn->query("SELECT id FROM products WHERE category_id=$cat_id OR sub_category_id=$cat_id LIMIT 1");
    if ($checkRes->num_rows > 0) {
        header("Location: ?page=admin&error=Cannot delete category. Products are still linked to it."); exit;
    }
    
    // 2. Delete the category (If it's a parent, any subs will be handled by DB foreign key or must be manually deleted if FK is missing)
    if ($conn->query("DELETE FROM categories WHERE id=$cat_id")) {
        header("Location: ?page=admin&msg=Category Deleted"); exit;
    } else {
        header("Location: ?page=admin&error=Category Deletion Failed: " . $conn->error); exit;
    }
}

// 4b. Admin: Manage Users (Remove/Promote)
if (isset($_GET['action']) && isAdminOrOwner()) {
    global $conn;
    $u_id = (int)($_GET['uid'] ?? 0);
    if ($_GET['action'] == 'delete_user') {
        $conn->query("DELETE FROM users WHERE id=$u_id");
    }
    if ($_GET['action'] == 'make_seller') { 
        // THIS ACTION: Admin manually promotes a user to seller (without seller data)
        // If the user later fills out the seller form, the full data will be saved.
        $conn->query("UPDATE users SET role='seller' WHERE id=$u_id");
    }
    header("Location: ?page=admin"); exit;
}

// 5. Admin/Seller: Product Deletion Logic - **(UPDATED FOR ORDERS CHECK)**
if (isset($_GET['action']) && $_GET['action'] == 'delete_product' && $loggedIn) {
    global $conn, $userRole, $userEmail;
    // Ensure $p_id is a secure integer before use
    $p_id = (int)$_GET['pid'];
    $redirectPage = isAdminOrOwner() ? 'admin' : 'profile';
    
    // Check if product exists and belongs to the seller/admin
    $p_data_res = $conn->query("SELECT seller_email, image FROM products WHERE id=$p_id");
    $p_data = $p_data_res->fetch_assoc();

    if ($p_data) {
        // Admin can delete any product OR Seller can delete their own product
        if (isAdminOrOwner() || ($userRole == 'seller' && $p_data['seller_email'] == $userEmail)) {
            
            // NEW LOGIC: Check for existing orders before allowing deletion
            $orderCheck = $conn->query("SELECT 1 FROM orders WHERE product_id=$p_id LIMIT 1");
            if ($orderCheck->num_rows > 0) {
                // If orders exist, forbid deletion as requested
                header("Location: ?page=$redirectPage&error=Cannot delete product. Orders are currently linked to this item.");
                exit;
            }

            // Delete product image file if it exists
            if (!empty($p_data['image']) && file_exists($p_data['image'])) {
                @unlink($p_data['image']); // Use @ to suppress potential file deletion warnings
            }
            
            // Delete all dependencies before the product.
            // 1. Delete related cart items (clean up cart)
            $conn->query("DELETE FROM cart_items WHERE product_id = " . (int)$p_id);

            // 2. Execute Deletion of product
            if ($conn->query("DELETE FROM products WHERE id = " . (int)$p_id)) {
                // SUCCESS
                header("Location: ?page=$redirectPage&msg=Product Removed Successfully!"); 
                exit;
            } else {
                // FAILURE
                header("Location: ?page=$redirectPage&error=Deletion Failed (DB Error): " . $conn->error);
                exit;
            }
        }
    }
    // If product not found or permission denied
    header("Location: ?page=$redirectPage&error=Permission Denied or Product Not Found"); 
    exit;
}


// 6. Seller/Admin: Bulk Discount
if (isset($_POST['apply_bulk_discount']) && isSellerOrAbove()) {
    global $conn, $userEmail;
    $discount = (int)$_POST['discount_percent'];
    $whereClause = isAdminOrOwner() ? "1" : "seller_email = '$userEmail'"; 
    $discount = max(0, min(99, $discount));
    $conn->query("UPDATE products SET discount = $discount WHERE $whereClause");
    header("Location: ?page=profile&msg=Bulk Discount Applied"); exit;
}

// 7. Add Product (Updated to handle 4 images)
if (isset($_POST['add_product']) && isSellerOrAbove()) {
    global $conn, $userEmail;
    $title = $conn->real_escape_string($_POST['title']);
    $price = (float)$_POST['price'];
    $catId = (int)$_POST['category_id'];
    $subId = (int)$_POST['subcategory_id'];
    $disc = (int)$_POST['discount'];
    $desc = $conn->real_escape_string($_POST['description']);
    
    // Array to hold all image paths
    $img_paths = [
        'image1' => null,
        'image2' => null,
        'image3' => null,
        'image4' => null
    ];

    // Handle Product Image Uploads (now for up to 4 images)
    if (!empty($_FILES['product_image_1']['name'])) {
        $img_paths['image1'] = uploadFile($_FILES['product_image_1'], 'product_');
    }
    if (!empty($_FILES['product_image_2']['name'])) {
        $img_paths['image2'] = uploadFile($_FILES['product_image_2'], 'product_');
    }
    if (!empty($_FILES['product_image_3']['name'])) {
        $img_paths['image3'] = uploadFile($_FILES['product_image_3'], 'product_');
    }
    if (!empty($_FILES['product_image_4']['name'])) {
        $img_paths['image4'] = uploadFile($_FILES['product_image_4'], 'product_');
    }
    
    $catNameRes = $conn->query("SELECT name FROM categories WHERE id=$catId");
    // FIX: Apply escaping to prevent SQL syntax errors from apostrophes (e.g., Men's Fashion)
    $catName = $conn->real_escape_string($catNameRes->fetch_assoc()['name'] ?? 'Uncategorized');

    // Updated SQL to include 3 new image columns (image2, image3, image4)
    $sql = "INSERT INTO products (title, price, image, image2, image3, image4, category, category_id, sub_category_id, seller_email, discount, description, orders_count) 
            VALUES ('$title', '$price', '".($img_paths['image1'] ?? '')."', '".($img_paths['image2'] ?? '')."', '".($img_paths['image3'] ?? '')."', '".($img_paths['image4'] ?? '')."', '$catName', $catId, $subId, '$userEmail', $disc, '$desc', 0)";
    
    if($conn->query($sql)) header("Location: ?page=profile&msg=Product Added");
    else echo $conn->error;
    exit;
}

// 8. User: Instant Seller Upgrade - **(ENSURES ROLE PERSISTS)**
if (isset($_POST['upgrade_seller']) && $loggedIn && $userRole == 'user') {
    global $conn, $userEmail;
    
    $sName = $conn->real_escape_string($_POST['seller_name']);
    $cNo = $conn->real_escape_string($_POST['contact_no']);
    $iNo = $conn->real_escape_string($_POST['identity_no']);
    $locPath = null;
    
    // Handle Live Location File Upload
    if (!empty($_FILES['live_location']['name'])) {
        $locPath = uploadFile($_FILES['live_location'], 'location_');
    }

    if (empty($sName) || empty($cNo) || empty($iNo) || !$locPath) {
        header("Location: ?page=profile&error=All seller information fields, including the location file, are required."); 
        exit;
    }

    // Set role directly to 'seller' and update new columns
    $sql = "UPDATE users SET role='seller', seller_name='$sName', contact_no='$cNo', identity_no='$iNo', location_data='$locPath' WHERE email='$userEmail'";
    
    if ($conn->query($sql)) {
        $_SESSION['role'] = 'seller'; // Update session immediately
        header("Location: ?page=profile&msg=Congratulations! You are now an active Seller."); 
        exit;
    } else {
        header("Location: ?page=profile&error=Seller Upgrade Failed (DB Error). Make sure you ran the required SQL query! Error: " . $conn->error); 
        exit;
    }
}

// --- DATA FETCHING ---
$settings = [];
$setRes = $conn->query("SELECT * FROM site_settings");
while($r = $setRes->fetch_assoc()) $settings[$r['setting_key']] = $r['setting_value']; 

$primaryColor = $settings['site_color'] ?? '#007bff'; // Default primary color

// Fetch Categories Tree
$cats = [];
$cRes = $conn->query("SELECT * FROM categories ORDER BY parent_id ASC, name ASC");
while($c = $cRes->fetch_assoc()) {
    if($c['parent_id'] == NULL) $cats[$c['id']] = ['info'=>$c, 'subs'=>[]];
    else if(isset($cats[$c['parent_id']])) $cats[$c['parent_id']]['subs'][] = $c;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>oceanstore - Sri Lanka</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://www.gstatic.com/firebasejs/9.18.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.18.0/firebase-auth-compat.js"></script>
    <style>
        /* CSS Variables for Dynamic Color */
        :root {
            --primary-color: <?=$primaryColor?>;
            --store-name-color: #dc3545;
        }

        /* General Store Colors */
        body { background: #f7f9fc; padding-top: 60px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .navbar { background-color: #ffffff !important; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-bottom: 3px solid var(--primary-color); }
        .store-name { color: var(--store-name-color); } /* Attractive red for 'store' part of name */
        
        /* Apply Dynamic Primary Color */
        .text-primary { color: var(--primary-color) !important; }
        .bg-primary { background-color: var(--primary-color) !important; }
        .btn-primary { background-color: var(--primary-color) !important; border-color: var(--primary-color) !important; }
        .btn-outline-primary { color: var(--primary-color) !important; border-color: var(--primary-color) !important; }
        .btn-outline-primary:hover { background-color: var(--primary-color) !important; color: white !important; }
        .cat-link:hover { background: #e9ecef; color: var(--primary-color); }
        
        /* 🐬 Logo Styling for Jumping Dolphin 🐬 */
        .logo-icon {
            position: relative;
            display: inline-block;
            line-height: 1; /* Ensure icons align properly */
        }
        .logo-icon .fa-water {
            position: absolute;
            top: 2px;
            left: -5px;
            color: #00bcd4; /* Water color */
            font-size: 1.2em;
            opacity: 0.7;
            z-index: 1;
        }
        .logo-icon .fa-dolphin {
            position: relative;
            z-index: 2;
            color: var(--primary-color);
            transform: rotate(-10deg);
        }

        /* Sidebar: Default OPEN on Desktop */
        .sidebar { 
            height: 100vh; 
            position: fixed; 
            left: 0; 
            top: 0; 
            width: 250px; 
            background: #fff; 
            z-index: 1050; 
            overflow-y: auto; 
            padding-top: 60px; 
            transform: translateX(0); /* Default OPEN state */
            transition: transform 0.3s, margin-left 0.3s; 
            box-shadow: 2px 0 5px rgba(0,0,0,0.1); 
        }
        /* State when user closes the sidebar */
        .sidebar.closed { transform: translateX(-250px); }
        .cat-link { display: block; padding: 10px 20px; color: #333; text-decoration: none; border-bottom: 1px solid #eee; }
        .sub-cat-link { padding-left: 40px; font-size: 0.9em; color: #666; }

        /* Content Shifting for Desktop */
        @media (min-width: 992px) {
            body { padding-left: 250px; } /* Default open: push content right */
            
            /* Shift everything left when sidebar is closed */
            body.sidebar-closed { padding-left: 0; }
            .sidebar.closed { transform: translateX(-250px); }
        }

        /* Mobile Sidebar (Default CLOSED on small screens) */
        @media (max-width: 991px) {
            .sidebar { transform: translateX(-250px); } 
            .sidebar.active { transform: translateX(0); } /* Toggled open on mobile */
            body { padding-left: 0; }
        }

        /* Animations */
        .fade-in { animation: fadeIn 1s ease-in; }
        .slide-up { animation: slideUp 0.8s ease-out; }
        @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
        @keyframes slideUp { from { transform: translateY(20px); opacity:0; } to { transform: translateY(0); opacity:1; } }
        
        /* Product Card/Carousel */
        .card { border: none; transition: transform 0.2s; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .card:hover { transform: translateY(-5px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .discount-badge { position: absolute; top: 10px; right: 10px; background: #ff6b6b; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; z-index: 10; }
        .text-shadow { text-shadow: 1px 1px 3px rgba(0,0,0,0.8); }
    </style>
</head>
<body class="<?php echo !isset($_COOKIE['sidebar_closed']) || $_COOKIE['sidebar_closed'] != 'true' ? '' : 'sidebar-closed'; ?>">

<div class="sidebar <?php echo !isset($_COOKIE['sidebar_closed']) || $_COOKIE['sidebar_closed'] != 'true' ? '' : 'closed'; ?>" id="mainSidebar">
    <div class="p-3">
        <h5 class="fw-bold text-center mt-3">Product Categories</h5>
        <?php foreach($cats as $main): ?>
            <a href="?page=category&id=<?=$main['info']['id']?>" class="cat-link fw-bold">
                <i class="fas fa-folder me-2"></i> <?=$main['info']['name']?>
            </a>
            <?php foreach($main['subs'] as $sub): ?>
                <a href="?page=category&id=<?=$sub['id']?>" class="cat-link sub-cat-link">
                    <i class="fas fa-arrow-right me-1 small"></i> <?=$sub['name']?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</div>

<nav class="navbar navbar-expand-lg navbar-light fixed-top" id="mainNavbar">
    <div class="container-fluid">
        <button class="btn me-2" onclick="toggleSidebar()"><i class="fas fa-bars fa-lg text-primary"></i></button>
        
        <a class="navbar-brand fw-bold" href="?">
            <span class="logo-icon me-2">
                <i class="fas fa-water"></i>
                <i class="fas fa-dolphin"></i>
            </span>
            <span class="text-primary">ocean</span><span class="store-name">store</span>
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navContent">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navContent">
            <div class="navbar-nav ms-auto align-items-center">
                <a href="?" class="nav-link text-dark">Home</a>
                <a href="?page=all_products" class="nav-link text-dark">All Products</a>
                <?php if($loggedIn): ?>
                    <a href="?page=profile" class="nav-link text-primary"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    <?php if(isAdminOrOwner()): ?><a href="?page=admin" class="nav-link text-warning fw-bold"><i class="fas fa-cogs"></i> Admin</a><?php endif; ?>
                    <form method="post" class="d-inline"><button name="logout" class="btn btn-sm btn-outline-danger ms-2 rounded-pill px-3">Logout</button></form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid mt-3" style="min-height: 80vh;">
    
    <div onclick="toggleSidebar()" id="overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1040;"></div>

<?php
$page = $_GET['page'] ?? 'home';

// Helper to render products (Updated for conditional deletion based on existing orders)
function renderProductCard($p, $showDelete = false) {
    global $loggedIn, $userRole, $userEmail, $conn;
    
    // Check permission for deletion
    $canDelete = false;
    $hasOrders = false;
    
    if ($showDelete) {
        if (isAdminOrOwner() || ($userRole == 'seller' && $p['seller_email'] == $userEmail)) {
            // **FIX/FEATURE:** Check for orders (Implements the rule: Cannot delete if orders exist)
            $p_id = (int)$p['id'];
            $orderCheck = $conn->query("SELECT 1 FROM orders WHERE product_id=$p_id LIMIT 1");
            if ($orderCheck->num_rows == 0) {
                $canDelete = true; // Only allow deletion if no orders exist
            } else {
                $hasOrders = true; // Flag for displaying "Orders Exist" button
            }
        }
    }

    $discount_percent = $p['discount'] ?? 0;
    
    $finalPrice = $discount_percent > 0 ? round($p['price'] * (100 - $discount_percent)/100, 2) : $p['price'];
    $img = $p['image'] && file_exists($p['image']) ? $p['image'] : 'https://via.placeholder.com/300x200?text=No+Image';
    
    echo '<div class="col-6 col-md-3 col-lg-2 mb-4 fade-in">
            <div class="card h-100">
                '.($discount_percent > 0 ? '<div class="discount-badge">-'.$discount_percent.'</div>' : '').'
                <img src="'.$img.'" class="card-img-top" style="height:160px;object-fit:cover">
                <div class="card-body p-2 d-flex flex-column">
                    <h6 class="card-title text-truncate" style="font-size:0.9rem">'.$p['title'].'</h6>
                    <div class="mt-auto">
                        <p class="mb-1 text-primary fw-bold">Rs. '.number_format($finalPrice, 2).'</p>
                        '.($discount_percent > 0 ? '<small class="text-muted text-decoration-line-through" style="font-size:0.8rem">Rs. '.number_format($p['price'], 2).'</small>' : '').'
                    </div>';
                    
    // Conditional display of Delete/Orders Exist/Buy buttons
    if ($canDelete) {
        $deleteLink = "?page=". (isAdminOrOwner() ? 'admin' : 'profile') ."&action=delete_product&pid=".$p['id'];
        echo '<a href="'.$deleteLink.'" onclick="return confirm(\'Are you sure you want to delete this product? This will also remove any associated cart items.\')" class="btn btn-sm btn-danger mt-2"><i class="fas fa-trash"></i> Delete</a>';
    } else if ($showDelete && $hasOrders) {
         // Show "Cannot Delete (Orders Exist)" message
         echo '<button class="btn btn-sm btn-secondary mt-2 disabled" title="Cannot delete: Orders linked."><i class="fas fa-ban"></i> Orders Exist</button>';
    } else if ($loggedIn) {
        echo '<a href="?page=buy&id='.$p['id'].'" class="btn btn-sm btn-outline-primary mt-2">Buy</a>'; 
    } else {
        echo '<a href="?page=login" class="btn btn-sm btn-outline-secondary mt-2">Buy (Login Required)</a>';
    }

    echo '  </div>
            </div>
          </div>';
}


// --- HOME PAGE ---
if ($page == 'home') {
    // FIX: Using ?? null to prevent "Undefined array key" warnings if settings are missing
    $slider1 = (($settings['slider_1'] ?? null) && file_exists($settings['slider_1'])) ? $settings['slider_1'] : 'https://images.unsplash.com/photo-1607082348824-0a96f2a4b9da?auto=format&fit=crop&w=1200&q=80';
    $slider2 = (($settings['slider_2'] ?? null) && file_exists($settings['slider_2'])) ? $settings['slider_2'] : 'https://images.unsplash.com/photo-1472851294608-415522f96319?auto=format&fit=crop&w=1200&q=80';

    // 1. Carousel (Sliding Animation & Picture Changing)
    echo '
    <div class="row mb-4 slide-up">
        <div class="col-md-12">
            <div id="homeCarousel" class="carousel slide" data-bs-ride="carousel">
                <div class="carousel-inner rounded shadow">
                    <div class="carousel-item active">
                        <img src="'.$slider1.'" class="d-block w-100" style="height: 400px; object-fit: cover;" alt="Slider 1">
                        <div class="carousel-caption d-block">
                            <h2 class="fw-bold text-light text-shadow">'.($settings['welcome_msg'] ?? 'Welcome to oceanstore').'</h2>
                            
                            '.(!$loggedIn ? '
                                <div class="mt-4">
                                    <a href="?page=login" class="btn btn-primary btn-lg mx-2 slide-up" style="animation-delay: 0.5s;">Login</a>
                                    <a href="?page=register" class="btn btn-success btn-lg mx-2 slide-up" style="animation-delay: 0.7s;">Register</a>
                                </div>
                            ' : '').'
                        </div>
                    </div>
                    <div class="carousel-item">
                        <img src="'.$slider2.'" class="d-block w-100" style="height: 400px; object-fit: cover;" alt="Slider 2">
                        <div class="carousel-caption d-block">
                            <h2 class="fw-bold text-light text-shadow">Grab the best deals!</h2>
                            '.(!$loggedIn ? '
                                <div class="mt-4">
                                    <a href="?page=login" class="btn btn-primary btn-lg mx-2 slide-up" style="animation-delay: 0.5s;">Login</a>
                                    <a href="?page=register" class="btn btn-success btn-lg mx-2 slide-up" style="animation-delay: 0.7s;">Register</a>
                                </div>
                            ' : '').'
                        </div>
                    </div>
                </div>
                <button class="carousel-control-prev" type="button" data-bs-target="#homeCarousel" data-bs-slide="prev">
                    <span class="carousel-control-prev-icon"></span>
                </button>
                <button class="carousel-control-next" type="button" data-bs-target="#homeCarousel" data-bs-slide="next">
                    <span class="carousel-control-next-icon"></span>
                </button>
            </div>
        </div>
    </div>';

    // 2. Categories Grid (Show all categories on home page)
    echo '<div class="mb-5 fade-in">
            <h4 class="mb-3 fw-bold">Shop by Main Category</h4>
            <div class="row g-3">';
    foreach ($cats as $main) {
        echo '<div class="col-6 col-md-2">
                <a href="?page=category&id='.$main['info']['id'].'" class="card p-3 text-center text-decoration-none text-dark h-100 d-flex flex-column justify-content-center">
                    <i class="fas fa-tag fa-2x mb-2 text-primary"></i>
                    <span class="small fw-bold">'.$main['info']['name'].'</span>
                </a>
              </div>';
    }
    echo '</div></div>';

    // 3. Featured Products
    echo '<h4 class="mb-3 fw-bold">Featured Products (Latest)</h4><div class="row g-3">';
    $res = $conn->query("SELECT * FROM products ORDER BY id DESC LIMIT 12");
    while($p = $res->fetch_assoc()) renderProductCard($p);
    if($res->num_rows == 0) echo '<div class="col-12 alert alert-info">No products added yet.</div>';
    echo '</div>';
}

// --- CATEGORY VIEW (For category and all_products) ---
elseif ($page == 'category' || $page == 'all_products') {
    $where = "1";
    $title = "All Products";
    
    if (isset($_GET['id'])) {
        $catId = (int)$_GET['id'];
        $check = $conn->query("SELECT * FROM categories WHERE id=$catId")->fetch_assoc();
        $title = $check['name'] ?? 'Category Not Found';
        if ($check) {
             if ($check['parent_id'] == NULL) {
                $subs = [];
                $sRes = $conn->query("SELECT id FROM categories WHERE parent_id=$catId");
                while($s = $sRes->fetch_assoc()) $subs[] = $s['id'];
                $subs[] = $catId; 
                $ids = implode(',', $subs);
                $where = "(`category_id` IN ($ids) OR `sub_category_id` IN ($ids))"; // Fixed backticks from previous iteration
            } else {
                $where = "(`sub_category_id`=$catId)"; // Fixed backticks from previous iteration
            }
        }
    }

    echo '<h3 class="mb-4 fade-in">'.$title.'</h3><div class="row g-3">';
    $res = $conn->query("SELECT * FROM products WHERE $where ORDER BY id DESC");
    if($res->num_rows == 0) echo '<div class="col-12 alert alert-warning">No products found in this category.</div>';
    while($p = $res->fetch_assoc()) renderProductCard($p);
    echo '</div>';
}

// --- ADMIN PANEL (Site Settings, Orders, Users, All Products) ---
elseif ($page == 'admin' && isAdminOrOwner()) {
    $search = $_GET['search_order'] ?? '';
    if(isset($_GET['msg'])) echo '<div class="alert alert-success">'.htmlspecialchars($_GET['msg']).'</div>';
    if(isset($_GET['error'])) echo '<div class="alert alert-danger">'.htmlspecialchars($_GET['error']).'</div>';
    
    echo '<h2 class="slide-up">Admin Panel</h2>
            <div class="row slide-up">
            <div class="col-md-3 mb-3">
                <div class="card p-3 bg-primary text-white mb-3">
                    <h5 class="fw-bold">Site Settings</h5>
                    <form method="post" enctype="multipart/form-data" class="mt-2 text-dark">
                        <label class="small text-light">Slider Image 1 (Upload)</label>
                        <input name="slider_1" type="file" accept="image/*" class="form-control form-control-sm mb-2">
                        <label class="small text-light">Slider Image 2 (Upload)</label>
                        <input name="slider_2" type="file" accept="image/*" class="form-control form-control-sm mb-2">
                        
                        <label class="small text-light">Welcome Message</label>
                        <input name="welcome_msg" class="form-control form-control-sm mb-2" value="'.($settings['welcome_msg']??'').'">
                        
                        <label class="small text-light">Primary Site Color (Hex)</label>
                        <input name="site_color" type="color" class="form-control form-control-sm form-control-color mb-2" value="'.($settings['site_color']??'#007bff').'">
                        
                        <button name="update_site_settings" class="btn btn-light btn-sm w-100 mt-2">Update Settings</button>
                    </form>
                </div>
                
                <div class="card p-3 mt-3">
                    <h5 class="fw-bold">Add Category</h5>
                    <form method="post">
                        <input name="cat_name" class="form-control form-control-sm mb-2" placeholder="Category Name" required>
                        <select name="parent_id" class="form-select form-select-sm mb-2">
                            <option value="">Main Category (No Parent)</option>';
                            foreach($cats as $c) echo '<option value="'.$c['info']['id'].'">'.$c['info']['name'].'</option>';
    echo '              </select>
                        <button name="add_category" class="btn btn-success btn-sm w-100 mb-3">Add Category</button>
                    </form>
                    
                    <h6 class="mt-3 fw-bold">Manage Categories (Delete)</h6>
                    <ul class="list-group list-group-flush small">
                        '; foreach($cats as $c): 
                           // Main Category
                            echo '<li class="list-group-item d-flex justify-content-between align-items-center p-1">
                                    <span class="fw-bold">'.$c['info']['name'].' (Main)</span>
                                    <a href="?page=admin&action=delete_category&id='.$c['info']['id'].'" onclick="return confirm(\'WARNING: This will delete the main category. Make sure NO products are linked. Proceed?\')" class="text-danger"><i class="fas fa-trash"></i> Delete</a>
                                </li>';
                            // Sub Categories
                            foreach($c['subs'] as $sub):
                                echo '<li class="list-group-item d-flex justify-content-between align-items-center p-1 ps-4 bg-light">
                                        <span>&mdash; '.$sub['name'].' (Sub)</span>
                                        <a href="?page=admin&action=delete_category&id='.$sub['id'].'" onclick="return confirm(\'WARNING: This will delete the sub-category. Make sure NO products are linked. Proceed?\')" class="text-danger"><i class="fas fa-trash"></i> Delete</a>
                                    </li>';
                            endforeach;
                        endforeach; 
    echo '          </ul>
                </div>
            </div>

            <div class="col-md-9">
                <div class="card p-3 mb-4">
                    <h4 class="fw-bold">All Products (Deletion)</h4>
                    <p class="text-muted small">Admins can delete any product if no orders are linked.</p>
                    <div class="row g-3">';
                        $pRes = $conn->query("SELECT * FROM products ORDER BY id DESC LIMIT 12");
                        while($p = $pRes->fetch_assoc()) renderProductCard($p, true); 
                        if($pRes->num_rows == 0) echo '<div class="col-12 p-3 text-muted">No products available.</div>';
    echo '          </div>
                </div>


                <div class="card p-3 mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="m-0">All Orders</h4>
                        <form class="d-flex">
                            <input name="search_order" value="'.htmlspecialchars($search).'" class="form-control form-control-sm me-2" placeholder="Search ID, Buyer Email, Seller Email...">
                            <input type="hidden" name="page" value="admin">
                            <button class="btn btn-outline-primary btn-sm">Search</button>
                        </form>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead><tr><th>Date/Time</th><th>Product</th><th>Buyer Info</th><th>Seller</th><th>Amount</th><th>Status</th></tr></thead>
                            <tbody>';
                            // Owner/Admin has full visibility of all orders, including buyer/seller/item info.
                            $sql = "SELECT o.*, p.title, DATE_FORMAT(o.order_date, '%Y-%m-%d %H:%i') as order_datetime FROM orders o LEFT JOIN products p ON o.product_id=p.id WHERE o.id LIKE '%$search%' OR o.buyer_email LIKE '%$search%' OR o.seller_email LIKE '%$search%' ORDER BY o.id DESC LIMIT 30";
                            $oRes = $conn->query($sql);
                            while($o = $oRes->fetch_assoc()) {
                                echo '<tr>
                                        <td>'.$o['order_datetime'].'</td>
                                        <td>'.($o['title'] ?? 'N/A').'</td>
                                        <td><strong>'.$o['buyer_name'].'</strong> <br> <small>'.$o['buyer_email'].' | '.$o['phone1'].'</small></td>
                                        <td>'.$o['seller_email'].'</td>
                                        <td>Rs. '.number_format($o['total_price'], 2).'</td>
                                        <td><span class="badge bg-success">'.$o['status'].'</span></td>
                                      </tr>';
                            }
    echo '                  </tbody>
                        </table>
                    </div>
                </div>

                <div class="card p-3">
                    <h4 class="fw-bold">Manage Users & Sellers</h4>
                    <p class="text-muted small">Admins can add or remove sellers by email, and view all seller data. **All Sellers are shown here**</p>
                    <table class="table table-sm">
                        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Seller Info</th><th>Action</th></tr></thead>
                        <tbody>';
                        // **FEATURE:** Includes all seller data in the query for the Admin Panel
                        $uRes = $conn->query("SELECT id, name, email, role, user_id, seller_name, contact_no, identity_no, location_data FROM users WHERE role != 'owner'");
                        while($u = $uRes->fetch_assoc()) {
                            $badge_class = ($u['role'] == 'user') ? 'secondary' : (($u['role'] == 'seller') ? 'info' : 'primary');
                            $role_display = strtoupper(str_replace('_', ' ', $u['role']));

                            $seller_info = 'N/A';
                            if ($u['role'] == 'seller') {
                                // **FEATURE:** Display all seller details
                                $seller_info = 'Seller: ' . ($u['seller_name'] ?: 'N/A') . '<br>' .
                                               'Contact: ' . ($u['contact_no'] ?: 'N/A') . '<br>' .
                                               'ID: ' . ($u['identity_no'] ?: 'N/A');
                                
                                if ($u['location_data']) {
                                    $seller_info .= '<br><a href="' . $u['location_data'] . '" target="_blank" class="text-primary small"><i class="fas fa-map-marker-alt"></i> View Location</a>';
                                } else {
                                    $seller_info .= '<br>Location: N/A';
                                }
                            }

                            echo '<tr>
                                    <td>'.$u['name'].'</td>
                                    <td>'.$u['email'].'</td>
                                    <td><span class="badge bg-'.$badge_class.'">'.$role_display.'</span></td>
                                    <td>'.$seller_info.'</td>
                                    <td>
                                        <a href="?page=admin&action=delete_user&uid='.$u['id'].'" onclick="return confirm(\'Remove this user?\')" class="text-danger me-3"><i class="fas fa-trash"></i> Remove</a>';
                                        
                            // Actions for 'user' role
                            if ($u['role'] == 'user') {
                                echo '<a href="?page=admin&action=make_seller&uid='.$u['id'].'" class="btn btn-sm btn-success"><i class="fas fa-user-tag"></i> Make Seller</a>';
                            } 
                            echo '  </td>
                                  </tr>';
                        }
    echo '              </tbody>
                    </table>
                </div>
            </div>
          </div>';
}

// --- SELLER DASHBOARD (PROFILE) ---
elseif ($page == 'profile' && $loggedIn) {
    if(isset($_GET['msg'])) echo '<div class="alert alert-success">'.htmlspecialchars($_GET['msg']).'</div>';
    if(isset($_GET['error'])) echo '<div class="alert alert-danger">'.htmlspecialchars($_GET['error']).'</div>';
    
    echo '<div class="row">
            <div class="col-md-3 slide-up">
                <div class="card p-3 mb-3 text-center">
                    <i class="fas fa-user-circle fa-4x text-muted mb-2"></i>
                    <h5>'.htmlspecialchars($userEmail).'</h5>
                    <span class="badge bg-info">'.strtoupper(str_replace('_', ' ', $userRole)).'</span>
                </div>';
                
    if (isSellerOrAbove()) {
        // Display seller tools for active sellers and admins
        echo '<div class="card p-3 mb-3">
                <h6 class="fw-bold">Seller Tools</h6>
                <button class="btn btn-outline-primary btn-sm w-100 mb-2" data-bs-toggle="modal" data-bs-target="#addProductModal">Add New Product</button>
                <button class="btn btn-outline-danger btn-sm w-100" data-bs-toggle="modal" data-bs-target="#bulkDiscountModal">Apply Bulk Discount</button>
              </div>';
    } 
    // NEW BLOCK: User upgrades to seller instantly (replaces old application logic)
    elseif ($userRole == 'user') {
        echo '<div class="card p-3 mb-3">
                <h6 class="fw-bold">Become an oceanstore Seller</h6>
                <p class="small text-muted">Complete the fields below for instant seller access.</p>
                <form method="post" enctype="multipart/form-data">
                    <label class="small text-muted">Your Seller Name:</label>
                    <input name="seller_name" class="form-control form-control-sm mb-2" required>
                    <label class="small text-muted">Contact Number:</label>
                    <input name="contact_no" class="form-control form-control-sm mb-2" required>
                    <label class="small text-muted">Identity/NIC Number:</label>
                    <input name="identity_no" class="form-control form-control-sm mb-2" required>
                    <label class="small text-muted">Upload Live Location File (e.g., KML, TXT, Screenshot):</label>
                    <input name="live_location" type="file" class="form-control form-control-sm mb-3" required>
                    <button name="upgrade_seller" class="btn btn-success btn-sm w-100">Upgrade to Seller Instantly</button>
                </form>
              </div>';
    } 
    // END NEW BLOCK
    
    echo '</div>
          <div class="col-md-9 fade-in">
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#myorders">My Orders (Buying)</a></li>
                '.(isSellerOrAbove() ? '<li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#myproducts">My Products (Selling)</a></li>' : '').'
                '.(isSellerOrAbove() ? '<li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#sold">Orders Received (Selling)</a></li>' : '').'
            </ul>
            
            <div class="tab-content">
                <div class="tab-pane fade show active" id="myorders">
                    <h5>My Purchase History</h5>';
                    $res = $conn->query("SELECT o.*, p.title FROM orders o JOIN products p ON o.product_id=p.id WHERE o.buyer_email='$userEmail' ORDER BY o.id DESC");
                    if($res->num_rows == 0) echo '<p>No purchases yet.</p>';
                    while ($o = $res->fetch_assoc()) {
                        echo '<div class="card mb-2 p-3">
                                <div class="d-flex justify-content-between">
                                    <span><strong>'.$o['title'].'</strong> <br> <small>Seller: '.$o['seller_email'].' | Status: '.$o['status'].'</small></span>
                                    <span class="fw-bold text-success">Rs. '.number_format($o['total_price'], 2).'</span>
                                </div>
                              </div>';
                    }
    echo '          </div>';
    
    // Products I Sell (For Seller Deletion)
    if (isSellerOrAbove()) {
        echo '<div class="tab-pane fade" id="myproducts">
                <h5>My Active Products (Click Delete to remove)</h5>
                <p class="text-muted small">Sellers can delete their own products, but not if they have existing orders.</p>
                <div class="row g-3">';
                    $pRes = $conn->query("SELECT * FROM products WHERE seller_email='$userEmail' ORDER BY id DESC");
                    while($p = $pRes->fetch_assoc()) renderProductCard($p, true); 
                    if($pRes->num_rows == 0) echo '<div class="col-12 p-3 text-muted">You have no active products.</div>';
        echo '  </div>
              </div>';
    }
    
    // Orders received by this seller
    if (isSellerOrAbove()) {
        echo '<div class="tab-pane fade" id="sold">
                <h5>Orders Received</h5>
                <p class="text-muted">You can view order details of users here.</p>
                <table class="table table-bordered table-sm">
                    <thead><tr><th>Date</th><th>Item</th><th>Buyer Details</th><th>Address</th></tr></thead>
                    <tbody>';
                    $res = $conn->query("SELECT o.*, p.title, DATE_FORMAT(o.order_date, '%Y-%m-%d %H:%i') as order_datetime FROM orders o JOIN products p ON o.product_id=p.id WHERE o.seller_email='$userEmail' ORDER BY o.id DESC");
                    if($res->num_rows == 0) echo '<tr><td colspan="4">No orders received yet.</td></tr>';
                    while ($o = $res->fetch_assoc()) {
                        echo '<tr>
                                <td>'.$o['order_datetime'].'</td>
                                <td>'.$o['title'].'</td>
                                <td>
                                    '.$o['buyer_name'].'<br>
                                    <small>'.$o['buyer_email'].' | '.$o['phone1'].'</small>
                                </td>
                                <td>'.$o['delivery_address'].' (Landmark: '.$o['landmark'].')</td>
                              </tr>';
                    }
        echo '      </tbody>
                </table>
              </div>';
    }
    echo '  </div>
          </div>
          </div>';
          
    // MODALS FOR SELLERS
    if(isSellerOrAbove()) {
        // Add Product Modal (Updated for 4 image uploads and correct spacing)
        echo '<div class="modal fade" id="addProductModal" tabindex="-1">
                <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Add New Product</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <form method="post" enctype="multipart/form-data">
                        <input name="title" class="form-control mb-2" placeholder="Product Title" required>
                        <textarea name="description" class="form-control mb-2" placeholder="Description (Optional)"></textarea>
                        <input name="price" type="number" step="0.01" class="form-control mb-2" placeholder="Base Price (e.g. 1500.00)" required>
                        
                        <label class="small text-muted fw-bold mt-2">Product Images (Up to 4) - Optional:</label>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="small text-muted">Image 1 (Main):</label>
                                <input name="product_image_1" type="file" accept="image/*" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label class="small text-muted">Image 2:</label>
                                <input name="product_image_2" type="file" accept="image/*" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label class="small text-muted">Image 3:</label>
                                <input name="product_image_3" type="file" accept="image/*" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label class="small text-muted">Image 4:</label>
                                <input name="product_image_4" type="file" accept="image/*" class="form-control form-control-sm">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col">
                                <select name="category_id" class="form-select mb-2" required id="catSelect" onchange="filterSubs()">
                                    <option value="">-- Main Category --</option>';
                                    foreach($cats as $c) echo '<option value="'.$c['info']['id'].'">'.$c['info']['name'].'</option>';
        echo '                  </select>
                            </div>
                            <div class="col">
                                <select name="subcategory_id" class="form-select mb-2" id="subSelect"><option value="0">-- Sub Category (Optional) --</option></select>
                            </div>
                        </div>
                        <label class="small text-muted">Discount percentage (0-99):</label>
                        <input name="discount" type="number" class="form-control mb-3" placeholder="Discount % (0 for none)" min="0" max="99" value="0">
                        <button name="add_product" class="btn btn-primary w-100">Publish Product</button>
                    </form>
                </div></div></div></div>';

        // Bulk Discount Modal (Sellers can select all his items by a click)
        echo '<div class="modal fade" id="bulkDiscountModal" tabindex="-1">
                <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Apply Bulk Discount</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p>This will update the discount percentage for **ALL** '. (isAdminOrOwner() ? 'products on the site' : 'your products').'.</p>
                    <form method="post">
                        <input name="discount_percent" type="number" min="0" max="99" class="form-control mb-3" placeholder="New Discount %" required>
                        <button name="apply_bulk_discount" class="btn btn-danger w-100">Apply to All '. (isAdminOrOwner() ? 'Site Products' : 'My Items') .'</button>
                    </form>
                </div></div></div></div>';
    }
}

// --- BUY PRODUCT (Order Placement) ---
elseif ($page == 'buy' && $loggedIn) {
    $id = (int)$_GET['id'];
    $p = $conn->query("SELECT * FROM products WHERE id=$id")->fetch_assoc();
    if (!$p) { echo '<div class="alert alert-danger">Product not found!</div>'; }
    else {
        // Calculation
        $finalPrice = $p['price'];
        if ($p['discount'] > 0) $finalPrice = round($p['price'] * (100 - $p['discount'])/100, 2);

        if (isset($_POST['placeorder'])) {
            $name = $conn->real_escape_string($_POST['name']);
            $addr = $conn->real_escape_string($_POST['address']);
            $phone1 = $conn->real_escape_string($_POST['phone1']);
            $landmark = $conn->real_escape_string($_POST['landmark']);
            
            // Inserting full buyer information for COD
            $conn->query("INSERT INTO orders (product_id,buyer_email,seller_email,total_price,delivery_address,buyer_name,phone1,landmark,status) 
                          VALUES ($id,'$userEmail','{$p['seller_email']}','$finalPrice','$addr','$name','$phone1','$landmark','pending')");
            $conn->query("UPDATE products SET orders_count=orders_count+1 WHERE id=$id");
            echo '<div class="container text-center py-5 slide-up"><h1 class="text-success"><i class="fas fa-check-circle fa-4x mb-3"></i></h1><h3>Order Placed Successfully!</h3><p>You owe **Rs. '.number_format($finalPrice, 2).'** to the delivery agent.</p><a href="?page=profile" class="btn btn-primary">View My Orders</a></div>';
        } else {
            // FIX: Use the first image for display
            $img = $p['image'] && file_exists($p['image']) ? $p['image'] : 'https://via.placeholder.com/60';
            echo '<div class="row justify-content-center">
                    <div class="col-md-6 slide-up">
                        <div class="card p-4 shadow">
                            <h4>Checkout: '.$p['title'].'</h4>
                            <div class="d-flex align-items-center mb-3 p-3 bg-light rounded">
                                <img src="'.$img.'" width="60" class="me-3 rounded" style="object-fit:cover;">
                                <div>
                                    <h6 class="m-0 text-truncate">'.$p['title'].'</h6>
                                    <span class="text-success fw-bold">Final Price: Rs. '.number_format($finalPrice, 2).'</span>
                                    '.($p['discount']>0 ? '<span class="text-muted text-decoration-line-through small ms-2">Rs. '.number_format($p['price'], 2).'</span>' : '').'
                                </div>
                            </div>
                            <form method="post">
                                <input name="name" class="form-control mb-3" placeholder="Full Name" required>
                                <textarea name="address" class="form-control mb-3" rows="3" placeholder="Full Delivery Address" required></textarea>
                                <input name="phone1" class="form-control mb-3" placeholder="Mobile Number" required>
                                <input name="landmark" class="form-control mb-3" placeholder="Nearby Landmark (Optional)">
                                <button name="placeorder" class="btn btn-success btn-lg w-100 mt-3">Confirm Order (Cash on Delivery)</button>
                            </form>
                        </div>
                    </div>
                  </div>';
        }
    }
}

// --- AUTH PAGES ---
elseif ($page == 'login' && !$loggedIn) {
    echo '<div class="col-md-4 mx-auto mt-5 text-center fade-in">
            <h3>Login</h3>
            <div class="card p-4 shadow-sm">
                <input id="loginEmail" type="email" class="form-control mb-3" placeholder="Email">
                <input id="loginPass" type="password" class="form-control mb-3" placeholder="Password">
                <button onclick="login()" class="btn btn-primary w-100">Login</button>
                <div id="loginMsg" class="mt-2 text-danger"></div>
            </div>
          </div>';
}
elseif ($page == 'register' && !$loggedIn) {
    echo '<div class="col-md-4 mx-auto mt-5 text-center fade-in">
            <h3>Register</h3>
            <div class="card p-4 shadow-sm">
                <input id="regName" class="form-control mb-3" placeholder="Full Name">
                <input id="regEmail" type="email" class="form-control mb-3" placeholder="Email">
                <input id="regPass" type="password" class="form-control mb-3" placeholder="Password">
                <button onclick="register()" class="btn btn-success w-100">Create Account</button>
                <div id="regMsg" class="mt-2"></div>
            </div>
          </div>';
}

?>
</div>

<script>
// FIREBASE JAVASCRIPT LOGIC
<?=$firebaseConfig?>
firebase.initializeApp(firebaseConfig);
const auth = firebase.auth();

function register() {
    const name = document.getElementById('regName').value;
    const email = document.getElementById('regEmail').value;
    const pass = document.getElementById('regPass').value;
    document.getElementById('regMsg').innerHTML = 'Please wait...';
    
    auth.createUserWithEmailAndPassword(email, pass).then(u => {
        u.user.sendEmailVerification();
        // Save user details to SQL database via PHP backend
        fetch('', {method:'POST', body:new URLSearchParams({saveuser:1, email:email, name:name})});
        document.getElementById('regMsg').innerHTML = '<span class="text-success">Success! Verification email sent.</span>';
    }).catch(e => document.getElementById('regMsg').innerText = e.message);
}

function login() {
    const email = document.getElementById('loginEmail').value;
    const pass = document.getElementById('loginPass').value;
    document.getElementById('loginMsg').innerText = 'Logging in...';
    
    auth.signInWithEmailAndPassword(email, pass).then(c => {
        if(!c.user.emailVerified) { 
            auth.signOut(); 
            document.getElementById('loginMsg').innerText = "Please verify your email first.";
            return; 
        }
        // Redirect on successful login, passing email for session creation
        window.location.href = "?loggedin=1&email=" + encodeURIComponent(email);
    }).catch(e => document.getElementById('loginMsg').innerText = e.message);
}

// Sidebar Logic (For mobile/small devices and desktop toggle)
function toggleSidebar() {
    const sidebar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('overlay');
    const body = document.body;
    let isClosed;

    if (window.innerWidth < 992) {
        // Mobile behavior: overlay and simple toggle
        isClosed = !sidebar.classList.toggle('active');
        overlay.style.display = sidebar.classList.contains('active') ? 'block' : 'none';
    } else {
        // Desktop behavior: slide sidebar, shift content
        isClosed = sidebar.classList.toggle('closed');
        body.classList.toggle('sidebar-closed', isClosed);
    }
    
    // Save state in cookie for default open/close on refresh
    document.cookie = "sidebar_closed=" + isClosed + "; path=/";
}

// Dynamic Subcategory Select Logic for Add Product Modal
const allCats = <?=json_encode($cats)?>;
function filterSubs() {
    const catId = document.getElementById('catSelect').value;
    const subSelect = document.getElementById('subSelect');
    subSelect.innerHTML = '<option value="0">-- Sub Category (Optional) --</option>';
    if(catId && allCats[catId] && allCats[catId].subs) {
        allCats[catId].subs.forEach(sub => {
            subSelect.innerHTML += `<option value="${sub.id}">${sub.name}</option>`;
        });
    }
}

// Set default sidebar state on load
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('mainSidebar');
    const body = document.body;
    const isClosed = document.cookie.split('; ').find(row => row.startsWith('sidebar_closed='))?.split('=')[1] === 'true';
    
    if (window.innerWidth >= 992) {
        if (isClosed) {
            sidebar.classList.add('closed');
            body.classList.add('sidebar-closed');
        } else {
            sidebar.classList.remove('closed');
            body.classList.remove('sidebar-closed');
        }
    }
});


// PHP Session Creation after successful Firebase login
<?php if (isset($_GET['loggedin'])) {
    $e = $conn->real_escape_string($_GET['email']);
    $res = $conn->query("SELECT role, name FROM users WHERE email='$e'");
    
    $role = 'user'; // Default role
    if ($res->num_rows) {
        // User found: get their actual role (e.g., 'seller' or 'admin'). **FIX for Role Reversion**
        $u = $res->fetch_assoc();
        $role = $u['role'];
    } else {
        // User NOT found: **FIX for Admin Invisibility**
        // Create the user record now with a default name/ID if the initial registration POST failed.
        $n = explode('@', $e)[0]; // Default name to part of email
        $uid = "USR" . substr(md5($e), 0, 8);
        $conn->query("INSERT INTO users (email, name, role, verified, user_id) VALUES ('$e', '$n', 'user', 1, '$uid')");
        // Role remains 'user' until they upgrade
    }

    // Owner override
    if ($e === 'chanukamindiw2006@gmail.com') $role = 'owner';
    
    // Set session variables with the corrected role
    $_SESSION['email'] = $e;
    $_SESSION['role'] = $role;
    echo "location.href='?'";
} ?>
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>