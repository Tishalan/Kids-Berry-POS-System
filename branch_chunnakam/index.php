<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "kidsberry";
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];
    $user_type = $_POST['user_type'];
    
    if ($user_type == 'cashier') {
        $sql = "SELECT cashiers_id, name, email, password, status FROM cashier2 WHERE email = '$email'";
        $result = $conn->query($sql);
        if ($result->num_rows == 1) {
            $row = $result->fetch_assoc();
            if (password_verify($password, $row['password']) && $row['status'] == 'active') {
                $_SESSION['cashiers_id'] = $row['cashiers_id'];
                $_SESSION['cashier_name'] = $row['name'];
                $_SESSION['cashier_email'] = $row['email'];
                header("Location: cashier/billing1.php");
                exit();
            } else {
                $error_message = "Invalid email, password, or account is inactive.";
            }
        } else {
            $error_message = "Invalid email or password.";
        }
    } 
    elseif ($user_type == 'stock_keeper') {
        $sql = "SELECT stock_keeper_id, name, email, password, status FROM stock_keeper_users2 WHERE email = '$email'";
        $result = $conn->query($sql);
        if ($result->num_rows == 1) {
            $row = $result->fetch_assoc();
            if (password_verify($password, $row['password']) && $row['status'] == 'active') {
                $_SESSION['stock_keeper_id'] = $row['stock_keeper_id'];
                $_SESSION['stock_keeper_name'] = $row['name'];
                $_SESSION['stock_keeper_email'] = $row['email'];
                header("Location: stock_keeper/dashboard1.php");
                exit();
            } else {
                $error_message = "Invalid password or account is inactive.";
            }
        } else {
            $error_message = "Email not found.";
        }
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>KidsBerry Mega Centre | Login - Branch 2</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', system-ui, 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(145deg, #0c2a1b 0%, #1b4635 55%, #2f6b3a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
            padding: 1.5rem;
        }

        /* Floating background elements */
        .floating-assets {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .float-icon {
            position: absolute;
            font-size: 1.8rem;
            opacity: 0.2;
            animation: floatSlow 24s infinite linear;
            filter: drop-shadow(0 5px 8px rgba(0,0,0,0.1));
        }

        @keyframes floatSlow {
            0% { transform: translateY(110vh) rotate(0deg) scale(0.9); opacity: 0; }
            10% { opacity: 0.25; }
            80% { opacity: 0.25; }
            100% { transform: translateY(-10vh) rotate(12deg) scale(1.1); opacity: 0; }
        }

        /* Main card */
        .glass-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(2px);
            width: 100%;
            max-width: 440px;
            border-radius: 2rem;
            box-shadow: 0 28px 60px -18px rgba(0, 0, 0, 0.35), 0 2px 12px rgba(0,0,0,0.05);
            overflow: hidden;
            transition: all 0.2s ease;
            z-index: 10;
            position: relative;
        }

        /* Header section */
        .brand-header {
            background: linear-gradient(115deg, #1f4d2c 0%, #2f6b3a 100%);
            text-align: center;
            padding: 2rem 1.8rem 1.6rem;
        }

        .logo-badge {
            width: 85px;
            height: 85px;
            background: white;
            border-radius: 50%;
            margin: 0 auto 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 12px 20px -8px rgba(0, 0, 0, 0.3);
            transition: transform 0.25s ease;
        }

        .logo-badge:hover {
            transform: scale(1.02);
        }

        .logo-badge img {
            width: 60px;
            height: 60px;
            object-fit: contain;
        }

        .brand-header h1 {
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: white;
            line-height: 1.2;
        }

        .brand-header p {
            color: rgba(255, 255, 255, 0.88);
            font-size: 0.85rem;
            font-weight: 500;
            margin-top: 6px;
        }

        /* Role tabs - structured properly */
        .role-switch {
            display: flex;
            gap: 0.75rem;
            background: #f2f6f2;
            margin: 1.3rem 1.8rem 0 1.8rem;
            padding: 0.5rem;
            border-radius: 3rem;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.02), 0 2px 6px rgba(0,0,0,0.02);
        }

        .role-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            background: transparent;
            border: none;
            font-weight: 600;
            font-size: 0.95rem;
            padding: 0.75rem 0.4rem;
            border-radius: 2.2rem;
            cursor: pointer;
            transition: all 0.25s;
            color: #2d4a2e;
            font-family: inherit;
        }

        .role-btn i {
            font-size: 1rem;
        }

        .role-btn.active {
            background: #fff;
            color: #1e562a;
            box-shadow: 0 4px 12px rgba(30, 86, 42, 0.18);
            font-weight: 700;
            transform: translateY(-1px);
        }

        /* Form area - clean and structured */
        .form-container {
            padding: 2rem 1.9rem 2.2rem;
        }

        .input-group {
            margin-bottom: 1.5rem;
        }

        .input-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #2c5a2c;
            margin-bottom: 0.6rem;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            font-size: 1.1rem;
            color: #7faa7a;
            pointer-events: none;
            z-index: 2;
        }

        .input-field {
            width: 100%;
            padding: 0.9rem 3rem 0.9rem 2.8rem;
            font-size: 0.95rem;
            border: 2px solid #e2ede0;
            background: #ffffff;
            border-radius: 1.4rem;
            transition: all 0.2s;
            font-family: inherit;
            color: #1d2c1a;
            font-weight: 500;
        }

        .input-field:focus {
            outline: none;
            border-color: #3b8b40;
            box-shadow: 0 0 0 3px rgba(59, 139, 64, 0.15);
        }

        /* Password toggle - perfectly aligned */
        .toggle-eye {
            position: absolute;
            right: 1rem;
            font-size: 1.2rem;
            color: #8bb38b;
            cursor: pointer;
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.2s;
            z-index: 5;
            padding: 0.5rem;
        }

        .toggle-eye:hover {
            color: #2c5e2e;
        }

        /* Login button */
        .login-action {
            margin-top: 1.8rem;
        }

        .login-btn {
            width: 100%;
            background: linear-gradient(98deg, #2b6731, #4b914e);
            border: none;
            padding: 0.95rem;
            border-radius: 3rem;
            font-weight: 700;
            font-size: 1rem;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.7rem;
            cursor: pointer;
            transition: all 0.25s;
            box-shadow: 0 8px 18px rgba(43, 103, 49, 0.3);
            font-family: inherit;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 22px rgba(43, 103, 49, 0.35);
            background: linear-gradient(98deg, #247c31, #3faa48);
        }

        /* Error message styling */
        .error-msg {
            background: #fff0f0;
            border-left: 5px solid #e2584a;
            padding: 0.85rem 1rem;
            border-radius: 1rem;
            margin: 0 1.8rem 1rem 1.8rem;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #b13e32;
            font-weight: 500;
        }

        .admin-footer {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.75rem;
            border-top: 1px solid #e4f0e2;
            padding-top: 1.2rem;
            color: #5a7356;
        }

        .admin-footer a {
            color: #2d6a2f;
            text-decoration: none;
            font-weight: 600;
            transition: 0.2s;
        }

        .admin-footer a:hover {
            text-decoration: underline;
        }

        /* Responsive */
        @media (max-width: 520px) {
            body {
                padding: 1rem;
            }
            .glass-card {
                max-width: 100%;
            }
            .form-container {
                padding: 1.8rem 1.4rem;
            }
            .role-switch {
                margin: 1rem 1.2rem 0;
            }
            .error-msg {
                margin: 0 1.2rem 1rem 1.2rem;
            }
        }

        @media (min-width: 1400px) {
            .glass-card {
                max-width: 480px;
            }
        }
    </style>
</head>
<body>
<div class="floating-assets" id="floatingAssets"></div>

<div class="glass-card">
    <div class="brand-header">
        <div class="logo-badge">
            <img src="logo.jpg" alt="KidsBerry" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'%3E%3Ccircle cx=\'50\' cy=\'50\' r=\'48\' fill=\'%232c5e2e\'/%3E%3Ctext x=\'50\' y=\'68\' font-size=\'44\' text-anchor=\'middle\' fill=\'white\' font-family=\'monospace\'%3EKB%3C/text%3E%3C/svg%3E';">
        </div>
        <h1>Kids Berry Mega Centre </h1>
        <p>Main Branch · Secure Portal</p>
    </div>

    <?php if (!empty($error_message)): ?>
    <div class="error-msg">
        <i class="fas fa-shield-alt"></i>
        <span><?php echo htmlspecialchars($error_message); ?></span>
    </div>
    <?php endif; ?>

    <div class="role-switch">
        <button type="button" class="role-btn active" id="roleCashierBtn">
            <i class="fas fa-cash-register"></i> Cashier
        </button>
        <button type="button" class="role-btn" id="roleStockBtn">
            <i class="fas fa-boxes"></i> Stock Keeper
        </button>
    </div>

    <div class="form-container">
        <form method="POST" action="" id="loginForm" autocomplete="off">
            <input type="hidden" name="user_type" id="userTypeField" value="cashier">
            
            <div class="input-group">
                <label><i class="far fa-envelope" style="margin-right: 6px;"></i> Email Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" name="email" id="emailInput" class="input-field" 
                           placeholder="your@email.com" required
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
            </div>

            <div class="input-group">
                <label><i class="fas fa-key"></i> Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" name="password" id="passwordInput" class="input-field" 
                           placeholder="••••••••" required>
                    <i class="fas fa-eye-slash toggle-eye" id="togglePasswordIcon"></i>
                </div>
            </div>

            <div class="login-action">
                <button type="submit" class="login-btn" id="dynamicLoginBtn">
                    <i class="fas fa-arrow-right-to-bracket"></i>
                    <span id="btnText">Sign in as Cashier</span>
                </button>
            </div>
        </form>
        <div class="admin-footer">
            <!--<i class="fas fa-headset"></i> Need assistance? -->
            <!--<a href="admin_contact.php">Contact Administrator</a>-->
        </div>
    </div>
</div>

<script>
    // Floating background elements
    (function initFloatingElements() {
        const container = document.getElementById('floatingAssets');
        if (!container) return;
        const icons = ['🍓', '🍎', '🧸', '🚂', '🧩', '⚽', '🚗', '🐻‍❄️', '🍉', '🪁', '🌟', '🍭', '🎈', '🐼'];
        
        for (let i = 0; i < 16; i++) {
            const el = document.createElement('div');
            el.className = 'float-icon';
            el.textContent = icons[Math.floor(Math.random() * icons.length)];
            const size = 1.3 + Math.random() * 1.4;
            el.style.fontSize = `${size}rem`;
            el.style.left = `${Math.random() * 100}%`;
            el.style.animationDuration = `${16 + Math.random() * 20}s`;
            el.style.animationDelay = `${Math.random() * 20 - 8}s`;
            el.style.opacity = 0.12 + Math.random() * 0.18;
            container.appendChild(el);
        }
        
        for (let i = 0; i < 25; i++) {
            const particle = document.createElement('div');
            particle.style.position = 'absolute';
            particle.style.width = `${3 + Math.random() * 8}px`;
            particle.style.height = particle.style.width;
            particle.style.background = `rgba(180, 220, 160, ${0.2 + Math.random() * 0.3})`;
            particle.style.borderRadius = '50%';
            particle.style.left = `${Math.random() * 100}%`;
            particle.style.bottom = '-20px';
            particle.style.animation = `floatSlow ${12 + Math.random() * 18}s linear infinite`;
            particle.style.animationDelay = `${Math.random() * 15}s`;
            particle.style.opacity = 0;
            container.appendChild(particle);
        }
    })();

    // Role switching
    const cashierBtn = document.getElementById('roleCashierBtn');
    const stockBtn = document.getElementById('roleStockBtn');
    const userTypeField = document.getElementById('userTypeField');
    const dynamicBtnSpan = document.getElementById('btnText');
    const loginButton = document.getElementById('dynamicLoginBtn');
    const loginIcon = loginButton ? loginButton.querySelector('i') : null;

    function setActiveRole(role) {
        if (role === 'cashier') {
            cashierBtn.classList.add('active');
            stockBtn.classList.remove('active');
            userTypeField.value = 'cashier';
            dynamicBtnSpan.innerText = 'Sign in as Cashier';
            if (loginIcon) loginIcon.className = 'fas fa-arrow-right-to-bracket';
        } else {
            stockBtn.classList.add('active');
            cashierBtn.classList.remove('active');
            userTypeField.value = 'stock_keeper';
            dynamicBtnSpan.innerText = 'Sign in as Stock Keeper';
            if (loginIcon) loginIcon.className = 'fas fa-warehouse';
        }
    }

    cashierBtn.addEventListener('click', () => setActiveRole('cashier'));
    stockBtn.addEventListener('click', () => setActiveRole('stock_keeper'));

    // Password toggle - fully functional
    const passwordInput = document.getElementById('passwordInput');
    const toggleIcon = document.getElementById('togglePasswordIcon');

    if (toggleIcon && passwordInput) {
        toggleIcon.addEventListener('click', function(e) {
            e.preventDefault();
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            if (type === 'text') {
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            } else {
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            }
        });
    }

    // Form validation
    const loginForm = document.getElementById('loginForm');
    
    function showInlineError(message) {
        const existingErr = document.querySelector('.dynamic-inline-error');
        if (existingErr) existingErr.remove();
        
        const errDiv = document.createElement('div');
        errDiv.className = 'error-msg dynamic-inline-error';
        errDiv.style.margin = '0 1.8rem 1rem 1.8rem';
        errDiv.innerHTML = `<i class="fas fa-exclamation-triangle"></i><span>${message}</span>`;
        
        const roleSwitch = document.querySelector('.role-switch');
        if (roleSwitch && roleSwitch.parentNode) {
            roleSwitch.insertAdjacentElement('afterend', errDiv);
        } else {
            document.querySelector('.glass-card')?.insertBefore(errDiv, document.querySelector('.form-container'));
        }
        
        setTimeout(() => {
            if (errDiv) errDiv.remove();
        }, 3000);
    }
    
    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            const email = document.getElementById('emailInput').value.trim();
            const password = document.getElementById('passwordInput').value;
            
            if (!email) {
                e.preventDefault();
                showInlineError('Please enter your email address.');
                return false;
            }
            if (!email.includes('@')) {
                e.preventDefault();
                showInlineError('Please enter a valid email address.');
                return false;
            }
            if (!password) {
                e.preventDefault();
                showInlineError('Please enter your password.');
                return false;
            }
            return true;
        });
    }

    // Set focus and preserve role from previous selection
    window.addEventListener('DOMContentLoaded', function() {
        const emailField = document.getElementById('emailInput');
        if (emailField) emailField.focus();
        
        const currentUserType = document.getElementById('userTypeField')?.value;
        if (currentUserType === 'stock_keeper') {
            setActiveRole('stock_keeper');
        } else {
            setActiveRole('cashier');
        }
    });
</script>
</body>
</html>