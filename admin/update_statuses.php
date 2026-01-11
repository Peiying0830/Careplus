<?php if (!empty($apt['qr_code']) && in_array($apt['status'], ['confirmed', 'pending'])): ?>
    <div class="qr-section">
        <!-- viewQRCode is a JS function -->
        <button class="btn-link" onclick="viewQRCode('<?php echo $apt['qr_code']; ?>', <?php echo $apt['appointment_id']; ?>)">
            <i class="fas fa-qrcode"></i> View QR Code for Check-in
        </button>
        <p style="font-size: 0.85rem; color: #666; margin-top: 0.5rem;">
            <i class="fas fa-info-circle"></i> Show this QR code at the clinic reception
        </p>
    </div>
<?php endif; ?>

<div class="appointment-actions">
    <?php 
    $appointment_datetime = strtotime($apt['appointment_date'] . ' ' . $apt['appointment_time']);
    $now = time();
    $cancellation_deadline = $appointment_datetime - (24 * 60 * 60);
    $can_cancel = ($appointment_datetime > $now) && 
                  ($now < $cancellation_deadline) && 
                  in_array($apt['status'], ['pending', 'confirmed']);
    ?>
    
    <?php if ($can_cancel): ?>
        <button class="btn btn-sm btn-danger" onclick="cancelAppointment(<?php echo $apt['appointment_id']; ?>)">
            <i class="fas fa-times"></i> Cancel Appointment
        </button>
        <small style="display: block; width: 100%; color: #666; margin-top: 0.5rem; font-size: 0.8rem;">
            <i class="fas fa-info-circle"></i> Free cancellation up to 24 hours before
        </small>
    <?php elseif ($appointment_datetime > $now && in_array($apt['status'], ['pending', 'confirmed'])): ?>
        <small style="display: block; width: 100%; color: #f44336; font-weight: 600;">
            <i class="fas fa-exclamation-triangle"></i> Cancellation locked. Call: 03-1234-5678
        </small>
    <?php endif; ?>
    
    <?php if ($apt['status'] === 'completed'): ?>
        <button class="btn btn-sm btn-primary" onclick="viewMedicalRecord(<?php echo $apt['appointment_id']; ?>)">
            <i class="fas fa-file-medical"></i> View Medical Record
        </button>
    <?php endif; ?>
    
    <?php if ($apt['status'] === 'confirmed' && !empty($apt['checked_in_at'])): ?>
        <div style="padding: 0.75rem; background: #e8f5e9; border-radius: 8px; border-left: 4px solid #4caf50; margin-top:0.5rem;">
            <i class="fas fa-check-circle" style="color: #4caf50;"></i>
            <strong>Checked In</strong> at <?php echo date('g:i A', strtotime($apt['checked_in_at'])); ?>
        </div>
    <?php endif; ?>
</div>