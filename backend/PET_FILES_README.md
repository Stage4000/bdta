# Pet File Upload Feature - Documentation

## Overview
The pet file upload feature allows users to upload and manage documents and photos for each pet profile. This includes vaccination records, medical documents, photos, and other pet-related files.

## Features

### File Upload
- **Supported Formats**: JPG, JPEG, PNG, GIF, PDF
- **File Size Limit**: 10MB maximum
- **Storage Location**: `/backend/uploads/pets/{pet_id}/`
- **File Types**: Automatically categorized as "photo" (jpg, jpeg, png, gif) or "document" (pdf)

### File Management
- **Upload**: Upload files with optional descriptions
- **View**: View images inline or PDFs in browser
- **Download**: Download any file
- **Delete**: Remove files (also removes from filesystem)

### Security Features
1. **Authentication Required**: All file operations require user login
2. **File Type Validation**: 
   - Extension whitelist (jpg, jpeg, png, gif, pdf)
   - MIME type verification
   - Double-check to prevent file type spoofing
3. **Filename Sanitization**:
   - Remove path separators (/, \, ..)
   - Replace unsafe characters
   - Prevent directory traversal attacks
4. **File Size Limits**: 10MB maximum enforced on both client and server
5. **Secure File Serving**: Files served through authenticated endpoint, not directly accessible
6. **Error Handling**: Generic error messages (no sensitive database info leaked)

## Usage

### For End Users

#### Uploading Files
1. Navigate to a pet's edit page (Edit Pet button from pets list or client view)
2. Scroll to the "Documents & Photos" section
3. Click "Choose File" or drag and drop a file
4. (Optional) Add a description (e.g., "Rabies vaccine 2024")
5. Click "Upload File"
6. File appears in the uploaded files list immediately

#### Viewing/Downloading Files
- **Images**: Click on the thumbnail to view full size in new tab
- **PDFs**: Click "View" to open in browser
- **All Files**: Click "Download" to download to your computer

#### Deleting Files
1. Click the "Delete" button on any file card
2. Confirm the deletion
3. File is removed from both database and filesystem

### For Administrators

#### Database Schema
The `pet_files` table contains:
- `id`: Primary key
- `pet_id`: Foreign key to pets table (CASCADE delete)
- `file_type`: 'photo' or 'document'
- `file_name`: Unique filename on filesystem
- `original_name`: Original upload filename
- `file_size`: Size in bytes
- `mime_type`: MIME type of the file
- `description`: Optional user description
- `uploaded_by`: Foreign key to admin_users (NULL on user delete)
- `uploaded_at`: Timestamp of upload

#### API Endpoints

**Upload File**
```
POST /client/pet_files_upload.php
Parameters:
  - file: File upload
  - pet_id: Pet ID (integer)
  - description: Optional description (string)
  
Response (JSON):
{
  "success": true,
  "message": "File uploaded successfully",
  "file": {
    "id": 123,
    "type": "photo",
    "name": "pet_1_abc123.jpg",
    "original_name": "vaccine_record.jpg",
    "size": 245632,
    "description": "Rabies vaccine 2024"
  }
}
```

**List Files**
```
GET /client/pet_files_list.php?pet_id=1

Response (JSON):
{
  "success": true,
  "pet": {"id": 1, "name": "Buddy"},
  "files": [...],
  "count": 5
}
```

**View/Download File**
```
GET /client/pet_files_view.php?id=123
GET /client/pet_files_view.php?id=123&download=1
```

**Delete File**
```
POST /client/pet_files_delete.php
Parameters:
  - file_id: File ID (integer)
  
Response (JSON):
{
  "success": true,
  "message": "File deleted successfully",
  "file_deleted_from_disk": true
}
```

## File Storage

Files are organized by pet ID:
```
backend/uploads/pets/
├── 1/
│   ├── pet_1_abc123.jpg
│   └── pet_1_def456.pdf
├── 2/
│   └── pet_2_ghi789.jpg
└── .gitignore
```

The `.gitignore` file prevents uploaded files from being committed to version control while preserving the directory structure.

## Integration Points

### Pet Edit Page (pets_edit.php)
- File upload section appears only when editing existing pets (not when creating new)
- JavaScript handles file selection, upload, display, and deletion
- Real-time feedback for upload progress and errors

### Pets List (pets_list.php)
- Shows file count badge for each pet
- Includes file count in SQL query using LEFT JOIN

### Client View (clients_view.php)
- Displays file count for each pet
- Provides quick link to edit pet and manage files

## Maintenance Notes

### Database Migration
The `pet_files` table is automatically created when the database is initialized. No manual migration needed.

### Backup Considerations
When backing up the system, ensure both:
1. Database file (contains file metadata)
2. Uploads directory (contains actual files)

If restoring from backup, both must be restored to maintain consistency.

### Cleaning Up Orphaned Files
If files are deleted from the database but not from the filesystem (or vice versa), you may need to run a cleanup:

```php
// Check for database entries without files
$stmt = $conn->query("SELECT * FROM pet_files");
foreach ($stmt->fetchAll() as $file) {
    $path = "backend/uploads/pets/{$file['pet_id']}/{$file['file_name']}";
    if (!file_exists($path)) {
        echo "Missing file: {$path}\n";
        // Optionally delete DB record
    }
}

// Check for files without database entries
// Scan uploads directory and compare with database
```

### Disk Space Monitoring
With a 10MB per-file limit, monitor disk usage:
- Set up alerts when disk usage exceeds thresholds
- Consider implementing file archival for old/inactive pets
- Regularly review and clean up unnecessary files

## Troubleshooting

### Files Not Uploading
1. Check directory permissions: `chmod 755 backend/uploads/pets`
2. Verify PHP upload settings in `php.ini`:
   - `upload_max_filesize = 10M`
   - `post_max_size = 10M`
3. Check error logs for specific errors
4. Ensure user is logged in (session active)

### Files Not Displaying
1. Verify file exists in filesystem
2. Check database record matches file location
3. Ensure MIME type is correct
4. Check browser console for JavaScript errors

### Security Concerns
All uploaded files are:
- Stored outside the web root's direct access
- Served through authenticated endpoints only
- Validated for type and size
- Sanitized for filenames

However, always:
- Keep PHP updated
- Monitor for unusual upload patterns
- Set up virus scanning if needed
- Regular security audits

## Future Enhancements

Potential improvements:
1. **Image Thumbnails**: Generate thumbnails for faster loading
2. **File Categories**: Tag files (medical, training, photos, etc.)
3. **Bulk Upload**: Upload multiple files at once
4. **File Expiration**: Auto-delete old files after X days
5. **File Sharing**: Generate shareable links with expiration
6. **Audit Trail**: Track who viewed/downloaded files
7. **File Versioning**: Keep history of file updates
8. **OCR for PDFs**: Extract text for search
9. **Cloud Storage**: Option to use S3/Azure instead of local storage
10. **Virus Scanning**: Integrate ClamAV or similar

## Support

For issues or questions:
1. Check this documentation
2. Review error logs
3. Contact system administrator
