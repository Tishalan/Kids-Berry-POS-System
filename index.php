<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>KidsBerry Mega Centre | Choose Branch</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300..800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --deep-purple: #2e1065;
            --dark-purple: #4c1d95;
            --vibrant-purple: #7e22ce;
            --dark-green: #14532d;
            --forest-green: #166534;
            --mint: #4ade80;
            --pure-white: #ffffff;
            --off-white: #f8fafc;
            --shadow: 0 25px 50px -12px rgba(124, 58, 237, 0.3);
        }

        body {
            font-family: 'Inter', 'Outfit', system-ui, sans-serif;
            background: linear-gradient(135deg, var(--deep-purple) 0%, #1e3a8a 50%, var(--dark-green) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
            color: white;
        }

        /* Floating Toys Background */
        .toys-bg {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            pointer-events: none;
            z-index: 1;
            overflow: hidden;
        }

        .toy {
            position: absolute;
            font-size: 2.2rem;
            opacity: 0.15;
            animation: floatToy 18s linear infinite;
            user-select: none;
        }

        .toy:nth-child(2) { animation-delay: -3s; font-size: 1.8rem; }
        .toy:nth-child(3) { animation-delay: -7s; font-size: 2.5rem; }
        .toy:nth-child(4) { animation-delay: -12s; }
        .toy:nth-child(5) { animation-delay: -5s; font-size: 1.6rem; }

        @keyframes floatToy {
            0%   { transform: translateY(100vh) rotate(0deg); }
            100% { transform: translateY(-100px) rotate(360deg); }
        }

        .welcome-container {
            width: 100%;
            max-width: 1050px;
            margin: 0 auto;
            position: relative;
            z-index: 10;
        }

        .welcome-header {
            text-align: center;
            margin-bottom: 35px;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 16px;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(12px);
            padding: 14px 28px;
            border-radius: 9999px;
            border: 1px solid rgba(255,255,255,0.2);
            margin-bottom: 20px;
        }

        .logo img {
            width: 56px; height: 56px; border-radius: 14px; object-fit: cover;
        }

        .logo span {
            font-size: 2.1rem;
            font-weight: 800;
            font-family: 'Outfit', sans-serif;
            letter-spacing: -0.5px;
        }

        .welcome-header h1 {
            font-size: 2.3rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .welcome-header p {
            font-size: 1.05rem;
            opacity: 0.9;
            max-width: 500px;
            margin: 0 auto;
        }

        .branch-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 38px;
            max-width: 920px;
            margin: 0 auto;
        }

        .branch-card {
            background: rgba(255,255,255,0.98);
            color: #1e2937;
            border-radius: 32px;
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            display: block;
        }

        .branch-card:hover {
            transform: translateY(-18px) scale(1.04);
            box-shadow: 0 35px 70px -15px rgba(124, 58, 237, 0.45);
        }

        .card-header {
            padding: 38px 32px 26px;
            text-align: center;
        }

        .branch-icon {
            width: 98px;
            height: 98px;
            margin: 0 auto 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            transition: all 0.4s ease;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }

        .branch-card:nth-child(1) .branch-icon {
            background: linear-gradient(135deg, var(--vibrant-purple), #a855f7);
            color: white;
        }

        .branch-card:nth-child(2) .branch-icon {
            background: linear-gradient(135deg, var(--forest-green), var(--mint));
            color: white;
        }

        .branch-card:hover .branch-icon {
            transform: scale(1.15) rotate(12deg);
        }

        .branch-name {
            font-size: 1.9rem;
            font-weight: 800;
            font-family: 'Outfit', sans-serif;
            margin-bottom: 8px;
        }

        .branch-location {
            font-size: 0.92rem;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .card-body {
            padding: 26px 32px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
        }

        .feature-list li {
            padding: 11px 0;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.93rem;
        }

        .feature-list li i {
            color: var(--mint);
            font-size: 1.1rem;
        }

        .card-footer {
            padding: 30px 32px;
            text-align: center;
            background: white;
        }

        .login-button {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: linear-gradient(90deg, var(--vibrant-purple), #c026d3);
            color: white;
            padding: 15px 34px;
            border-radius: 9999px;
            font-weight: 700;
            font-size: 1.07rem;
            transition: all 0.4s ease;
            box-shadow: 0 10px 25px rgba(168, 85, 247, 0.4);
        }

        .branch-card:hover .login-button {
            gap: 18px;
            background: linear-gradient(90deg, #a855f7, #db2777);
            box-shadow: 0 15px 30px rgba(168, 85, 247, 0.5);
            transform: translateY(-2px);
        }

        .footer-note {
            text-align: center;
            margin-top: 50px;
            padding-top: 20px;
            opacity: 0.75;
            font-size: 0.85rem;
            border-top: 1px solid rgba(255,255,255,0.2);
        }

        /* Responsive */
        @media (max-width: 820px) {
            .branch-grid {
                grid-template-columns: 1fr;
                gap: 28px;
            }
            .welcome-header h1 { font-size: 1.95rem; }
        }

        @media (max-width: 480px) {
            .card-header, .card-body, .card-footer { padding-left: 24px; padding-right: 24px; }
            .branch-icon { width: 82px; height: 82px; font-size: 2.4rem; }
        }
    </style>
</head>
<body>

    <!-- Floating Toys Background -->
    <div class="toys-bg">
        <div class="toy" style="left: 8%; animation-duration: 22s;">🧸</div>
        <div class="toy" style="left: 22%; animation-duration: 26s;">🏀</div>
        <div class="toy" style="left: 38%; animation-duration: 19s;">🧱</div>
        <div class="toy" style="left: 55%; animation-duration: 24s;">🚀</div>
        <div class="toy" style="left: 72%; animation-duration: 21s;">🧩</div>
        <div class="toy" style="left: 85%; animation-duration: 28s;">🐻</div>
        <div class="toy" style="left: 15%; animation-duration: 17s;">🎈</div>
        <div class="toy" style="left: 65%; animation-duration: 25s;">🛍️</div>
    </div>

    <div class="welcome-container">
        <div class="welcome-header">
            <div class="logo">
                <img src="assets/img/logo.jpg" alt="KidsBerry" onerror="this.style.display='none'">
                <span>KidsBerry</span>
            </div>
            <h1>Welcome to KidsBerry Mega Centre</h1>
            <p>Select your branch to access the management system</p>
        </div>

        <div class="branch-grid">
            <!-- Mega Centre - Chunnakam -->
            <a href="branch_chunnakam/index.php" class="branch-card">
                <div class="card-header">
                    <div class="branch-icon"><i class="fas fa-store"></i></div>
                    <div class="branch-name">Kids Berry Mega Centre</div>
                    <div class="branch-location">
                        <i class="fas fa-map-marker-alt"></i> Chunnakam Branch
                    </div>
                </div>
                <!--<div class="card-body">-->
                <!--    <ul class="feature-list">-->
                <!--        <li><i class="fas fa-check-circle"></i> Full Inventory Management</li>-->
                <!--        <li><i class="fas fa-check-circle"></i> Billing & POS System</li>-->
                <!--        <li><i class="fas fa-check-circle"></i> Stock Transfer Capability</li>-->
                <!--        <li><i class="fas fa-check-circle"></i> Supplier Management</li>-->
                <!--    </ul>-->
                <!--</div>-->
                <div class="card-footer">
                    <div class="login-button">
                        <i class="fas fa-sign-in-alt"></i> Login to Mega Centre
                    </div>
                </div>
            </a>

            <!-- Jaffna Branch -->
            <a href="index1.php" class="branch-card">
                <div class="card-header">
                    <div class="branch-icon"><i class="fas fa-warehouse"></i></div>
                    <div class="branch-name">Kids Berry</div>
                    <div class="branch-location">
                        <i class="fas fa-map-marker-alt"></i> Jaffna Branch
                    </div>
                </div>
                <!--<div class="card-body">-->
                <!--    <ul class="feature-list">-->
                <!--        <li><i class="fas fa-check-circle"></i> Full Inventory Management</li>-->
                <!--        <li><i class="fas fa-check-circle"></i> Billing & POS System</li>-->
                <!--        <li><i class="fas fa-check-circle"></i> Stock Transfer Capability</li>-->
                <!--        <li><i class="fas fa-check-circle"></i> Supplier Management</li>-->
                <!--    </ul>-->
                <!--</div>-->
                <div class="card-footer">
                    <div class="login-button">
                        <i class="fas fa-sign-in-alt"></i> Login to Kids Berry
                    </div>
                </div>
            </a>
        </div>

        <div class="footer-note">
            <i class="fas fa-shield-alt"></i> Secure Access | KidsBerry Mega Centre Management System
        </div>
    </div>
</body>
</html>