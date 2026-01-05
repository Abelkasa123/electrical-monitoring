<?php
$page_title = "403 Forbidden";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Electrical Monitoring</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #e2e8f0;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .error-container {
            max-width: 600px;
            text-align: center;
            padding: 40px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }
        .error-code {
            font-size: 8rem;
            font-weight: 900;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 20px;
        }
        .btn-home {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-code">403</div>
        <h1>Access Denied</h1>
        <p class="lead">You don't have permission to access this resource.</p>
        <p class="text-muted">The server understood the request but refuses to authorize it.</p>
        <div class="mt-4">
            <a href="index.php" class="btn btn-home">
                <i class="fas fa-home me-2"></i>Go to Homepage
            </a>
            <a href="login.php" class="btn btn-outline-light ms-2">
                <i class="fas fa-sign-in-alt me-2"></i>Login
            </a>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
</body>
</html>