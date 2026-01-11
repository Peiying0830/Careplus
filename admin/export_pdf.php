<?php
/** @noinspection ALL */

// export_pdf.php
require_once __DIR__ . '/../config.php';
requireRole('admin');

if (!file_exists('fpdf.php')) {
    die("Error: fpdf.php not found.");
}
require('fpdf.php');

/** @var mysqli $conn */
$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Fetch Data (Same as before)
$stmt = $conn->prepare("SELECT u.email, u.user_type, u.created_at, u.is_active, a.first_name, a.last_name, a.phone, a.department FROM users u JOIN admins a ON u.user_id = a.user_id WHERE u.user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$adminData = $stmt->get_result()->fetch_assoc();

$countRes = $conn->query("SELECT COUNT(*) as total FROM symptom_checker_logs WHERE user_id = $userId");
$totalLogs = $countRes->fetch_assoc()['total'];

$logRes = $conn->query("SELECT action, description, created_at FROM symptom_checker_logs WHERE user_id = $userId ORDER BY created_at DESC LIMIT 25");

/**
 * --- This DocBlock tells the editor that these methods exist ---
 * @method SetFont($font, $style, $size)
 * @method SetTextColor($r, $g, $b)
 * @method SetFillColor($r, $g, $b)
 * @method SetXY($x, $y)
 * @method SetY($y)
 * @method Cell($w, $h, $txt, $border, $ln, $align, $fill, $link)
 * @method Rect($x, $y, $w, $h, $style)
 * @method Line($x1, $y1, $x2, $y2)
 * @method Ln($h)
 * @method AddPage($orientation, $size, $rotation)
 * @method AliasNbPages($alias)
 * @method Output($dest, $name, $isUTF8)
 * @method PageNo()
 */
class ProfessionalPDF extends FPDF {
    function Header() {
        $primary = [255, 140, 66];
        $this->SetFillColor($primary[0], $primary[1], $primary[2]);
        $this->Rect(0, 0, 210, 40, 'F');
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(15, 12);
        $this->SetFont('Arial', 'B', 22);
        $this->Cell(0, 10, 'CarePlus', 0, 1, 'L', false);
        
        $this->SetXY(15, 22);
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 10, 'Admin Account Profile & Activity Audit Report', 0, 1, 'L', false);
        
        $this->SetFont('Arial', '', 9);
        $this->SetXY(140, 12);
        $this->Cell(55, 5, 'Generated: ' . date('d M Y, H:i'), 0, 1, 'R', false);
    }

    function Footer() {
        $this->SetY(-20);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 5, 'CarePlus Smart Clinic Management Portal', 0, 1, 'L', false);
        $this->Cell(0, 5, 'Page ' . $this->PageNo(), 0, 0, 'R', false);
    }
}

// Instantiate with explicit variable type hint
/** @var ProfessionalPDF $pdf */
$pdf = new ProfessionalPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();

// Statistics Summary Box
$pdf->SetY(45);
$pdf->SetFillColor(245, 245, 245);
$pdf->Rect(15, 45, 180, 22, 'F');

// Labels
$pdf->SetTextColor(44, 62, 80);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(20, 50); $pdf->Cell(45, 5, 'ACCOUNT STATUS', 0, 0, 'L', false);
$pdf->SetXY(65, 50); $pdf->Cell(45, 5, 'DEPARTMENT', 0, 0, 'L', false);
$pdf->SetXY(110, 50); $pdf->Cell(45, 5, 'TOTAL LOGS', 0, 0, 'L', false);
$pdf->SetXY(155, 50); $pdf->Cell(40, 5, 'USER TYPE', 0, 0, 'L', false);

// Values
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetTextColor(255, 140, 66);
$statusText = ($adminData['is_active'] ?? false) ? 'ACTIVE' : 'INACTIVE';
$pdf->SetXY(20, 57); $pdf->Cell(45, 5, $statusText, 0, 0, 'L', false);
$pdf->SetXY(65, 57); $pdf->Cell(45, 5, strtoupper($adminData['department'] ?? 'N/A'), 0, 0, 'L', false);
$pdf->SetXY(110, 57); $pdf->Cell(45, 5, (string)$totalLogs, 0, 0, 'L', false);
$pdf->SetXY(155, 57); $pdf->Cell(40, 5, strtoupper($adminData['user_type'] ?? 'ADMIN'), 0, 0, 'L', false);

// Section 1: User Profile
$pdf->SetY(75);
$pdf->SetTextColor(44, 62, 80);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'Personal Information', 0, 1);

$pdf->SetFont('Arial', '', 10);
$pdf->Cell(40, 8, 'Full Name:', 0, 0); $pdf->Cell(0, 8, ($adminData['first_name'] ?? '') . ' ' . ($adminData['last_name'] ?? ''), 0, 1);
$pdf->Cell(40, 8, 'Email Address:', 0, 0); $pdf->Cell(0, 8, $adminData['email'] ?? '', 0, 1);
$pdf->Cell(40, 8, 'Contact No:', 0, 0); $pdf->Cell(0, 8, $adminData['phone'] ?? '', 0, 1);

$pdf->Ln(5);

// Section 2: Activity Table
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'Recent Activity Logs', 0, 1);

$pdf->SetFillColor(255, 140, 66);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(35, 10, ' TIMESTAMP', 0, 0, 'L', true);
$pdf->Cell(45, 10, ' ACTION', 0, 0, 'L', true);
$pdf->Cell(100, 10, ' DESCRIPTION', 0, 1, 'L', true);

$pdf->SetTextColor(44, 62, 80);
$pdf->SetFont('Arial', '', 8);
$fill = false;
while($row = $logRes->fetch_assoc()) {
    $pdf->SetFillColor(248, 248, 248);
    $pdf->Cell(35, 8, ' ' . date('d/m/Y H:i', strtotime($row['created_at'])), 0, 0, 'L', $fill);
    $pdf->Cell(45, 8, ' ' . strtoupper($row['action']), 0, 0, 'L', $fill);
    $desc = strlen($row['description']) > 65 ? substr($row['description'], 0, 62) . '...' : $row['description'];
    $pdf->Cell(100, 8, ' ' . $desc, 0, 1, 'L', $fill);
    $fill = !$fill;
}

$pdf->Output('D', "CarePlus_Admin_Report_" . date('Ymd') . ".pdf");
exit;