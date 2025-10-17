# Deletion Queue System

The deletion queue system ensures that records which were previously indexed but no longer meet the inclusion criteria are removed from the search index. This maintains the integrity and accuracy of your search results by automatically cleaning up outdated or excluded content.

## Overview

When content changes in your TYPO3 installation, some records might no longer be eligible for indexing due to:

- Pages marked with the `no_search` flag
- Pages with `doktype` values that no longer match the configured types
- Content elements with `CType` values that no longer match the configured types
- Records moved outside of the configured page trees
- Files marked as excluded from search
- Records that have been deleted or hidden

The deletion queue system identifies these records and removes them from both the indexing queue and the Algolia search index.

## Components

### DeletionDetectionService

The `DeletionDetectionService` is responsible for identifying records that should be removed from the search index. It works by:

1. Examining each indexing service configuration
2. Applying the same inclusion criteria used during normal indexing
3. Finding records that exist in the database but don't meet current inclusion criteria
4. Returning a list of records that should be deleted

### IndexDeletionQueueCommand

The `IndexDeletionQueueCommand` is a CLI command that uses the deletion detection service to:

1. Find records that should be deleted
2. Display what will be deleted (with optional dry-run mode)
3. Remove the records from both the indexing queue and the Algolia index
4. Provide detailed feedback and error handling

## Usage

### Running the Deletion Queue Command

The deletion queue command can be run manually or scheduled as a recurring task.

#### Manual Execution

```bash
# Run the deletion queue command
nrdev typo3 mkk:queue:index:deletion

# Run in dry-run mode to see what would be deleted without actually deleting
nrdev typo3 mkk:queue:index:deletion --dry-run
```

#### Scheduled Execution

The command can be scheduled in the TYPO3 Scheduler for automatic execution:

1. Go to **System > Scheduler** in the TYPO3 Backend
2. Create a new task of type "Execute console commands (scheduler)"
3. Select the command `mkv:queue:index:deletion`
4. Configure the frequency (recommended: daily or weekly)
5. Optionally add the `--dry-run` flag for testing

### Command Options

- `--dry-run`: Show what would be deleted without actually performing deletions
- `-h, --help`: Display help information for the command

### Command Output

The command provides detailed output about:

- How many records were found for deletion
- Which indexing services and table types are affected
- Progress during deletion processing
- Success/error counts and detailed error information

Example output:
```
Detecting records that should be removed from search index...

Found 15 records that should be removed from the search index:

  Service 1 - pages: 5 records (UIDs: 123, 124, 125, 126, 127)
  Service 1 - tt_content: 8 records (UIDs: 456, 457, 458, 459, 460, 461, 462, 463)
  Service 2 - sys_file_metadata: 2 records (UIDs: 789, 790)

Do you want to proceed with deleting these records from the search index? (yes/no) [no]:
> yes

Processing deletions...
 15/15 [============================] 100%

Successfully queued 15 records for deletion from the search index.
```

## Integration with Existing Workflow

### Automatic Integration

The deletion queue system works alongside the existing indexing system:

1. **Normal indexing** continues to add and update records as usual
2. **Record deletion events** are handled by existing event listeners
3. **Deletion queue** periodically cleans up records that no longer meet inclusion criteria

### Manual Integration

You can integrate deletion queue processing into your existing maintenance workflows:

1. Run deletion queue before full reindexing operations
2. Include it in regular database cleanup procedures
3. Execute it after major configuration changes to indexing services

## Best Practices

### Scheduling

- **Frequency**: Run the deletion queue daily or weekly, depending on how frequently your content changes
- **Timing**: Schedule during low-traffic periods to minimize impact
- **Monitoring**: Enable logging to track deletions and identify patterns

### Testing

- Always test with `--dry-run` first when setting up or changing configurations
- Monitor logs for errors or unexpected deletion patterns
- Validate that search results are accurate after deletion operations

### Maintenance

- Review deletion logs regularly to ensure the system is working correctly
- Adjust indexing service configurations if too many records are being deleted
- Consider the impact of large deletion operations on your Algolia usage limits

## Error Handling

The deletion queue system includes comprehensive error handling:

- **Database errors** are logged with detailed information
- **Search engine errors** are handled gracefully and logged
- **Invalid configurations** are detected and reported
- **Failed deletions** don't stop the processing of other records

### Monitoring and Debugging

Enable detailed logging by configuring your TYPO3 logging framework to capture messages from:

- `MeineKrankenkasse\Typo3SearchAlgolia\Command\IndexDeletionQueueCommand`
- `MeineKrankenkasse\Typo3SearchAlgolia\Service\DeletionDetectionService`
- `MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\RecordHandler`

Example logging configuration in `LocalConfiguration.php`:
```php
$GLOBALS['TYPO3_CONF_VARS']['LOG']['MeineKrankenkasse']['Typo3SearchAlgolia'] = [
    'writerConfiguration' => [
        \TYPO3\CMS\Core\Log\LogLevel::INFO => [
            \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                'logFileInfix' => 'algolia_deletion_queue'
            ],
        ],
    ],
];
```

## Configuration Considerations

### Indexing Service Settings

The deletion queue system respects all indexing service configurations:

- **Page constraints**: Only pages within configured page trees are considered
- **Content type filters**: Only configured content types are processed  
- **Document type filters**: Only configured document types are processed
- **File collections**: Only files in configured collections are processed

### Performance Impact

- The deletion detection process queries the database to compare current records against inclusion criteria
- Large installations may experience some database load during deletion detection
- Consider running during off-peak hours for large installations
- The actual deletion from Algolia respects rate limits and includes proper error handling

## Troubleshooting

### Common Issues

1. **No records found for deletion**: This is normal if all records meet current inclusion criteria
2. **Too many records being deleted**: Review your indexing service configurations for accuracy
3. **Deletion failures**: Check Algolia API credentials and rate limits
4. **Performance issues**: Consider adjusting batch sizes or running frequency

### Debug Commands

```bash
# Run in dry-run mode to see what would be affected
nrdev typo3 mkv:queue:index:deletion --dry-run

# Check indexing service configurations
nrdev typo3 backend:user list

# Verify queue status
nrdev typo3 mkv:queue:index:worker --documentsToIndex=0
```