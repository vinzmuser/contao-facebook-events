<?php

namespace Oneup\FacebookEvents;

use Contao\Database;
use Contao\File;
use Facebook\GraphObject;
use GuzzleHttp\Client;

class EventProcessor
{
    protected $guzzleClient;
    protected $database;
    protected $config;

    public function __construct(array $config)
    {
        $this->guzzleClient = new Client();
        $this->database = Database::getInstance();
        $this->config = $config;
    }

    public function process(GraphObject $data, GraphObject $image)
    {
        $facebookId = $data->getProperty('id');

        // check if event exists
        if (null !== ($event = $this->checkIfEventExists($facebookId))) {
            $this->updateEvent($event, $data, $image);
            return;
        }

        $this->createEvent($data, $image);
        return;
     }

    protected function generateAlias($input)
    {
        $varValue = standardize(\String::restoreBasicEntities($input));

        $objAlias = $this->database->prepare("SELECT id FROM tl_calendar_events WHERE alias=?")
            ->execute($varValue)
        ;

        // Add ID to alias
        if ($objAlias->numRows)
        {
            $maxId = $this->database->prepare("SELECT MAX(id) FROM tl_calendar_events")
                ->execute()
            ;

            $varValue .= '-' . $maxId;
        }

        return $varValue;
    }

    protected function checkIfEventExists($facebookId)
    {
        $result = $this->database->prepare('SELECT * FROM tl_calendar_events WHERE facebook_id=? LIMIT 1')
            ->execute($facebookId)
        ;

        if ($result->numRows > 0) {
            return $result->first();
        }

        return null;
    }

    /**
     * Todo: Author Id
     *
     * @param GraphObject $data
     * @param GraphObject $image
     */
    protected function createEvent(GraphObject $data, GraphObject $image)
    {
        $timestamps = $this->getTimestamps($data);

        // store image
        $file = $this->writePicture($data->getProperty('id'), $image);

        $this->database->prepare("
            INSERT INTO tl_calendar_events
                (pid, tstamp, title, alias, author, addTime, startTime, startDate, endTime, endDate, teaser, addImage, singleSRC, size, floating, imagemargin, published, facebook_id)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")

            ->execute(
                $this->config['calendar'],
                time(),
                $data->getProperty('name'),
                $this->generateAlias($data->getProperty('name')),

                // Author
                $this->config['author'],

                // Timestamps
                $timestamps['addTime'],
                $timestamps['startTime'],
                $timestamps['startDate'],
                $timestamps['endTime'],
                $timestamps['endDate'],

                sprintf('<p>%s</p>', $data->getProperty('description')),

                // Add singleSRC
                1,
                $file->uuid,
                $this->config['imageSize'],
                $this->config['imageFloating'],
                $this->config['imageMargin'],

                true,
                $data->getProperty('id')
            )
        ;

        // Get the Id of the inserted Event
        $insertedEvent = $this->database->prepare('SELECT id FROM tl_calendar_events WHERE facebook_id = ?')
            ->execute($data->getProperty('id'))
        ;

        $eventId = $insertedEvent->next()->id;

        // Insert ContentElement
        $this->database->prepare("
            INSERT INTO tl_content
                (pid, ptable, sorting, tstamp, type, headline, text, floating, sortOrder, perRow, cssID, space, com_order, com_template)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")

            ->execute(
                $eventId,
                'tl_calendar_events',
                128,
                time(),
                'text',
                serialize(array('unit' => 'h1', 'value' => $data->getProperty('name'))),
                sprintf('<p>%s</p>', $data->getProperty('description')),
                'above',
                'ascending',
                4,
                serialize(array('', '')),
                serialize(array('', '')),
                'ascending',
                'com_default'
            )
        ;
    }

    protected function updateEvent($eventObj, GraphObject $data, GraphObject $image)
    {
        $timestamps = $this->getTimestamps($data);

        $file = $this->writePicture($data->getProperty('id'), $image);

        $this->database->prepare('UPDATE tl_calendar_events SET title = ?, teaser = ?, singleSRC = ?, addTime = ?, startTime = ?, startDate = ?, endTime = ?, endDate = ? WHERE facebook_id = ?')
            ->execute(
                $data->getProperty('name'),
                sprintf('<p>%s</p>', $data->getProperty('description')),
                $file->uuid,

                // Timestamps
                $timestamps['addTime'],
                $timestamps['startTime'],
                $timestamps['startDate'],
                $timestamps['endTime'],
                $timestamps['endDate'],

                $data->getProperty('id')
            )
        ;

        // Update Text ContentElement
        $this->database->prepare('UPDATE tl_content SET headline = ?, text = ? WHERE type = ? AND pid = ? AND ptable = ?')
            ->execute(
                serialize(array('unit' => 'h1', 'value' => $data->getProperty('name'))),
                sprintf('<p>%s</p>', $data->getProperty('description')),
                'text',
                $eventObj->id,
                'tl_calendar_events'
            )
        ;
    }

    /**
     * Write a picture from a /event/picture request and
     * return the file model including the uuid.
     *
     * @param $id
     * @param GraphObject $image
     * @return \FilesModel
     */
    protected function writePicture($id, GraphObject $image)
    {
        // fetch file
        $content = $this->guzzleClient->get($image->getProperty('url'))->getBody();

        $file = new File(sprintf('files/facebook-events/%s.jpg', $id));
        $file->write($content);
        $file->close();

        return $file->getModel();
    }

    protected function getTimestamps(GraphObject $data)
    {
        $start = new \DateTime($data->getProperty('start_time'));
        $addTime = !in_array('end_time', $data->getPropertyNames()) ? '' : '1';

        $offset = $start->getTimezone()->getOffset($start);
        $startDate = new \DateTime($start->format('Y-m-d'));
        $startDate = $startDate->modify(sprintf('+%s seconds', $offset))->format('U');

        $startTime = $start;
        $startTime = $startTime->modify(sprintf('+%s seconds', $offset))->format('U');

        if (in_array('end_time', $data->getPropertyNames())) {
            $end = new \DateTime($data->getProperty('end_time'));

            $endDate = new \DateTime($end->format('Y-m-d'));
            $endDate = $endDate->modify(sprintf('+%s seconds', $offset))->format('U');

            $endTime = $end;
            $endTime = $endTime->modify(sprintf('+%s seconds', $offset))->format('U');
        } else {
            $endDate = $startDate;
            $endTime = $startTime;
        }

        return [
            'addTime' => $addTime,
            'startDate' => $startDate,
            'startTime' => $startTime,
            'endDate' => $endDate,
            'endTime' => $endTime
        ];
    }
}