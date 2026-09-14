<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Consult Now - JT Accounting</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@500;600;700&display=swap" rel="stylesheet">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    :root {
      --primary: #2c3e50;
      --secondary: #3498db;
      --accent: #1abc9c;
      --light: #f8f9fa;
      --dark: #34495e;
      --success: #2ecc71;
      --card-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
      --transition: all 0.3s ease;
    }

    body {
      font-family: 'Poppins', sans-serif;
      background: linear-gradient(135deg, #1a2a6c, #b21f1f, #1a2a6c);
      background-size: 400% 400%;
      animation: gradientBG 15s ease infinite;
      min-height: 100vh;
      padding: 20px;
      display: flex;
      justify-content: center;
      align-items: center;
      color: #333;
    }

    @keyframes gradientBG {
      0% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
      100% { background-position: 0% 50%; }
    }

    .form-container {
      max-width: 650px;
      width: 100%;
      background: rgba(255, 255, 255, 0.95);
      border-radius: 16px;
      overflow: hidden;
      box-shadow: var(--card-shadow);
      position: relative;
      z-index: 2;
      margin: 20px;
      transition: var(--transition);
    }

    .form-container:hover {
      transform: translateY(-5px);
      box-shadow: 0 12px 35px rgba(0, 0, 0, 0.2);
    }

    .form-header {
      background: linear-gradient(135deg, var(--primary), var(--dark));
      color: white;
      padding: 30px;
      text-align: center;
      position: relative;
    }

    .form-header h2 {
      font-size: 2.2rem;
      margin-bottom: 10px;
      font-family: 'Montserrat', sans-serif;
      font-weight: 700;
    }

    .form-header p {
      font-size: 1.1rem;
      opacity: 0.9;
      max-width: 500px;
      margin: 0 auto;
    }

    .form-content {
      padding: 40px;
    }

    .form-group {
      margin-bottom: 25px;
      position: relative;
    }

    label {
      display: block;
      margin-bottom: 10px;
      font-weight: 600;
      color: var(--primary);
      font-size: 1.05rem;
      display: flex;
      align-items: center;
    }

    label i {
      margin-right: 10px;
      color: var(--secondary);
      width: 22px;
      text-align: center;
    }

    .input-wrapper {
      position: relative;
    }

    input {
      width: 100%;
      padding: 15px 15px 15px 50px;
      border: 2px solid #e1e5eb;
      border-radius: 10px;
      font-size: 1rem;
      font-family: 'Poppins', sans-serif;
      transition: var(--transition);
      background-color: var(--light);
    }

    input:focus {
      outline: none;
      border-color: var(--secondary);
      box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
    }

    .input-wrapper i {
      position: absolute;
      left: 18px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--dark);
      font-size: 1.1rem;
    }

    .guide {
      font-size: 0.9rem;
      color: #6c757d;
      margin-top: 8px;
      margin-left: 32px;
      line-height: 1.4;
    }

    .date-display {
      margin-top: 15px;
      font-size: 1rem;
      color: var(--dark);
      background-color: #f0f8ff;
      padding: 15px 20px;
      border-radius: 10px;
      border: 2px dashed #bde0fe;
      font-weight: 500;
      text-align: center;
    }

    button {
      margin-top: 20px;
      width: 100%;
      padding: 16px;
      background: linear-gradient(135deg, var(--accent), #16a085);
      color: white;
      border: none;
      border-radius: 10px;
      font-size: 1.1rem;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      letter-spacing: 0.5px;
      font-family: 'Montserrat', sans-serif;
      box-shadow: 0 4px 15px rgba(26, 188, 156, 0.3);
    }

    button:hover {
      background: linear-gradient(135deg, #16a085, var(--accent));
      transform: translateY(-3px);
      box-shadow: 0 6px 20px rgba(26, 188, 156, 0.4);
    }

    button i {
      margin-right: 10px;
    }

    .back-button {
      margin-top: 20px;
      text-align: center;
    }

    .back-button a {
      display: inline-block;
      color: white;
      background: linear-gradient(135deg, var(--primary), var(--dark));
      padding: 12px 30px;
      border-radius: 10px;
      text-decoration: none;
      font-weight: 500;
      transition: var(--transition);
      box-shadow: 0 4px 12px rgba(44, 62, 80, 0.2);
    }

    .back-button a:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 15px rgba(44, 62, 80, 0.3);
    }

    .back-button a i {
      margin-right: 8px;
    }

    .file-input-container {
      position: relative;
      overflow: hidden;
      display: inline-block;
      width: 100%;
    }

    .file-input-container input[type="file"] {
      position: absolute;
      left: 0;
      top: 0;
      opacity: 0;
      width: 100%;
      height: 100%;
      cursor: pointer;
    }

    .file-input-label {
      display: block;
      padding: 15px 20px;
      background-color: #e9f7fe;
      border: 2px dashed #5dade2;
      border-radius: 10px;
      text-align: center;
      color: var(--dark);
      font-weight: 500;
      cursor: pointer;
      transition: var(--transition);
    }

    .file-input-label:hover {
      background-color: #d1f2eb;
      border-color: var(--accent);
    }

    .file-input-label i {
      margin-right: 10px;
      color: var(--secondary);
      font-size: 1.2rem;
    }

    .form-footer {
      text-align: center;
      padding: 20px 0;
      color: #6c757d;
      font-size: 0.9rem;
      border-top: 1px solid #eaeaea;
      margin-top: 20px;
    }

    .form-footer a {
      color: var(--secondary);
      text-decoration: none;
      transition: var(--transition);
    }

    .form-footer a:hover {
      color: var(--primary);
      text-decoration: underline;
    }

    @media (max-width: 768px) {
      .form-container {
        margin: 10px;
      }
      
      .form-header {
        padding: 20px;
      }
      
      .form-content {
        padding: 30px 20px;
      }
      
      .form-header h2 {
        font-size: 1.8rem;
      }
      
      input {
        padding: 14px 14px 14px 45px;
      }
    }

    @media (max-width: 480px) {
      .form-header h2 {
        font-size: 1.6rem;
      }
      
      .form-header p {
        font-size: 1rem;
      }
      
      input {
        padding: 12px 12px 12px 40px;
        font-size: 0.95rem;
      }
      
      label {
        font-size: 1rem;
      }
    }
  </style>
</head>

<body>
  <div class="form-container">
    <div class="form-header">
      <h2><i class="fas fa-handshake"></i> Professional Consultation</h2>
      <p>Complete this form to schedule a consultation with our accounting experts</p>
    </div>
    
    <div class="form-content">
      <form action="submit.php" method="POST" enctype="multipart/form-data">
        <div class="form-group">
          <label for="company_name"><i class="fas fa-building"></i> Company Name</label>
          <div class="input-wrapper">
            <i class="fas fa-building"></i>
            <input type="text" name="company_name" pattern="[A-Za-z0-9\s.,'&\-()]+"
                   title="Letters, numbers, and basic special characters allowed" required
                   placeholder="Enter your company name">
          </div>
        </div>
        
        <div class="form-group">
          <label for="email"><i class="fas fa-envelope"></i> Company Email Address</label>
          <div class="input-wrapper">
            <i class="fas fa-envelope"></i>
            <input type="email" name="email" required placeholder="company@example.com">
          </div>
        </div>
        
        <div class="form-group">
          <label for="phone"><i class="fas fa-phone"></i> Company Phone Number</label>
          <div class="input-wrapper">
            <i class="fas fa-phone"></i>
            <input type="tel" name="phone" id="phone" pattern="^09\d{9}$"
                   title="Optional. Must start with 09 and be exactly 11 digits if filled."
                   placeholder="09123456789 (optional)">
          </div>
          <div class="guide">Optional. Must be a valid 11-digit number starting with 09 if filled.</div>
        </div>
        
        <div class="form-group">
          <label for="full_name"><i class="fas fa-user"></i> Full Name</label>
          <div class="input-wrapper">
            <i class="fas fa-user"></i>
            <input type="text" name="full_name" pattern="[A-Za-z\s.,'&\-]+" 
                   title="Letters and basic special characters allowed" required
                   placeholder="Last Name, Given Name, Middle Name">
          </div>
          <div class="guide">Last Name, Given Name, Middle Name</div>
        </div>
        
        <div class="form-group">
          <label for="position"><i class="fas fa-briefcase"></i> Position in the Company</label>
          <div class="input-wrapper">
            <i class="fas fa-briefcase"></i>
            <input type="text" name="position" pattern="[A-Za-z\s]+" 
                   title="Only letters allowed" required placeholder="Your position in the company">
          </div>
        </div>
        
        <div class="form-group">
          <label for="permits"><i class="fas fa-file-alt"></i> Business Permits and Licenses:</label>
          <div class="file-input-container">
            <div class="file-input-label">
              <i class="fas fa-cloud-upload-alt"></i> 
              <span id="file-name">Click to upload business permits</span>
            </div>
            <input type="file" name="permit" id="permit" required>
          </div>
        </div>
        
        <?php
          $today = date("F j, Y");
          echo '<div class="date-display">';
          echo '<i class="fas fa-calendar-alt"></i> Date Today: ' . $today;
          echo '</div>';
          echo '<input type="hidden" name="date_today" value="' . date("Y-m-d") . '">';
        ?>
        
        <button type="submit"><i class="fas fa-paper-plane"></i> Submit Consultation Request</button>
      </form>
      
      <div class="back-button">
        <a href="index1.html"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
      </div>
    </div>
    
    <div class="form-footer">
      <p>© 2023 JT Accounting Services | <a href="#"><i class="fas fa-shield-alt"></i> Privacy Policy</a></p>
    </div>
  </div>

  <script>
    // Update file name display when file is selected
    document.getElementById('permit').addEventListener('change', function(e) {
      const fileName = e.target.files[0] ? e.target.files[0].name : "Click to upload business permits";
      document.getElementById('file-name').textContent = fileName;
    });
    
    // Add animation to form on load
    document.addEventListener('DOMContentLoaded', function() {
      const form = document.querySelector('.form-container');
      form.style.opacity = '0';
      form.style.transform = 'translateY(20px)';
      
      setTimeout(() => {
        form.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        form.style.opacity = '1';
        form.style.transform = 'translateY(0)';
      }, 100);
    });
  </script>
</body>
</html>