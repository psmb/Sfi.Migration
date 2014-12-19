<?php
namespace Sfi\Migration\Command;

/*																		*
 * This script belongs to the TYPO3 Flow package "Sfi.Migration".		 *
 *																		*
 *																		*/

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cli\CommandController;
use TYPO3\TYPO3CR\Domain\Model\NodeType;
use TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\Neos\Domain\Service\NodeSearchService;

use TYPO3\Flow\Object\ObjectManagerInterface;
use TYPO3\Flow\Resource\ResourceManager;
use TYPO3\Media\Domain\Model\Asset;
use TYPO3\Media\Domain\Model\Image;
use TYPO3\Media\Domain\Model\ImageVariant;
use TYPO3\Media\Domain\Repository\ImageRepository;
use TYPO3\Media\Domain\Repository\AssetRepository;

/**
 * @Flow\Scope("singleton")
 */
class MigrationCommandController extends CommandController {

	/**
	 * @Flow\Inject
	 * @var ObjectManagerInterface
	 */
	protected $objectManager;

	/**
	 * @Flow\Inject(lazy = FALSE)
	 * @var \Doctrine\Common\Persistence\ObjectManager
	 */
	protected $entityManager;


	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Service\NodeTypeManager
	 */
	protected $nodeTypeManager;

	/**
	 * @var \TYPO3\TYPO3CR\Domain\Service\Context
	 */
	protected $context;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Service\ContextFactoryInterface
	 */
	protected $contextFactory;

	/**
	 * @Flow\Inject
	 * @var NodeSearchService
	 */
	protected $nodeSearchService;

	/**
	 * @Flow\Inject
	 * @var ResourceManager
	 */
	protected $resourceManager;

	/**
	 * @Flow\Inject
	 * @var ImageRepository
	 */
	protected $imageRepository;	

	/**
	 * @Flow\Inject
	 * @var AssetRepository
	 */
	protected $assetRepository;

	/**
	 * Index action
	 *
	 * Some desk
	 * 
	 * @return string
	 */
	public function indexCommand() {
		$allowedImageFileTypes = array("jpg", "jpeg");

		$this->context = $this->contextFactory->create(array('workspaceName' => 'live'));
		$rootNode = $this->context->getNode('/sites/sfi/unsorted');

		$news = $this->getNewsByCat(1);
		foreach ($news as $newsItem) {
			$term = serialize("originalIdentifier").serialize((string)$newsItem['uid']);
			$nodes = $this->nodeSearchService->findByProperties($term, array('Sfi.News:News'), $this->context);
			if(count($nodes)){
				echo "Node ".$newsItem['uid']." skipped\n";
			}else{
				$newsNodeTemplate = new \TYPO3\TYPO3CR\Domain\Model\NodeTemplate();
				$newsNodeTemplate->setNodeType($this->nodeTypeManager->getNodeType('Sfi.News:News'));
				$newsNodeTemplate->setProperty('originalIdentifier',$newsItem['uid']);
				$newsNodeTemplate->setProperty('title',$newsItem['title']);
				$newsNodeTemplate->setProperty('teaser',$newsItem['short']);
				if($newsItem['datetime']){
					$date = new \DateTime();
					$date->setTimestamp($newsItem['datetime']);
					$newsNodeTemplate->setProperty('date',$date);				
				}
				$newsNodeTemplate->setProperty('author',$newsItem['author']);
				$newsNode = $rootNode->createNodeFromTemplate($newsNodeTemplate);

				if($newsItem['bodytext']){
					$bodytext = $newsItem['bodytext'];
					$bodytext = preg_replace('@<(p|div|span|i|b|strong|em)[^>]*></\1>@ui','',$bodytext);
					$bodytext = preg_replace('/^((?!<p>).+)$/uim','<p>$1</p>',$bodytext);
					//Not well tested!
					$bodytext = preg_replace_callback(
						'@<link\s+([^>]*)>([^<]*)</link>@ui',
						function ($matches) {
							//If link to page, we drop that link, as they have changed anyways
							if(is_numeric($matches[1])){
								return $matches[2];
							}else if(preg_match('@(http)([^\s]+)@ui',$matches[0],$matches2)){ //If url, then turn into normal link record:tt_news:2806 
								return '<a href="'.$matches2[0].'">'.$matches[2].'</a>';
							}else if(preg_match('@(record:tt_news:)([\d]+)@ui',$matches[0],$matches2)){ //If url, then turn into normal link record:tt_news:2806 
								return $matches[2];
							}else{ //just in case...
								return $matches[2];
							}
						},
						$bodytext
					);

					$mainContentNode = $newsNode->getNode('main');
					$bodytextTemplate = new \TYPO3\TYPO3CR\Domain\Model\NodeTemplate();
					$bodytextTemplate->setNodeType($this->nodeTypeManager->getNodeType('TYPO3.Neos.NodeTypes:Text'));
					$bodytextTemplate->setProperty('text',$bodytext);
					$mainContentNode->createNodeFromTemplate($bodytextTemplate);
				}
				if($newsItem['image']){
					$coverPhotoNode = $newsNode->getNode('coverPhoto');
					$galleryNode = $newsNode->getNode('gallery');
					$captions = explode(',',$newsItem['imagealttext']);
					$isFirst = true;
					foreach(explode(',',$newsItem['image']) as $i => $img_file){
						if(in_array(pathinfo(strtolower($img_file), PATHINFO_EXTENSION),$allowedImageFileTypes)){
							$file = '/www/sfi.ru/web/uploads/pics/'.$img_file;
							if(file_exists($file)){
								$image = $this->importImage($file);
								$imageNodeTemplate = new \TYPO3\TYPO3CR\Domain\Model\NodeTemplate();
								$imageNodeTemplate->setNodeType($this->nodeTypeManager->getNodeType('TYPO3.Neos.NodeTypes:Image'));
								$imageNodeTemplate->setProperty('image',$image);
								if(isset($captions[$i])) {
									$imageNodeTemplate->setProperty('alternativeText',$captions[$i]);
								}
								if ($isFirst) {
									$coverPhotoNode->createNodeFromTemplate($imageNodeTemplate);
									$isFirst = false;
								} else {
									$galleryNode->createNodeFromTemplate($imageNodeTemplate);
								}
								echo "- ".$img_file." imported\n";
							}
						}else{
							echo "Illegal image file extension of file: ".$img_file."\n";
						}
					}
				}
				if($newsItem['news_files']){
					$assetsNode = $newsNode->getNode('assets');
					$assets = array();
					foreach(explode(',',$newsItem['news_files']) as $i => $file_name){
						$file = '/www/sfi.ru/web/uploads/media/'.$file_name;
						if(file_exists($file)){
							$asset = $this->importFile($file);
							if($asset){
								$assets[] = $asset;
							}
						}
					}
					$fileNodeTemplate = new \TYPO3\TYPO3CR\Domain\Model\NodeTemplate();
					$fileNodeTemplate->setNodeType($this->nodeTypeManager->getNodeType('TYPO3.Neos.NodeTypes:AssetList'));
					$fileNodeTemplate->setProperty('assets',$assets);
					$assetsNode->createNodeFromTemplate($fileNodeTemplate);
				}
				
				echo "Node ".$newsItem['uid']." migrated\n";
			}
		}
		return "Done!";
	}


	private function getNewsByCat($cat){
		//$connection = $this->entityManager->getConnection(); //Use this if import from the same DB

		/* Import from other database */
		$dsn = 'mysql:dbname=;host=127.0.0.1;charset=utf8';
		$user = '';
		$password = '';
		try {
			$connection = new \PDO($dsn, $user, $password);
		} catch (PDOException $e) {
			die ('Connection failed: ' . $e->getMessage());
		}

		$sql = 'SELECT tt_news.* FROM tt_news
  INNER JOIN tt_news_cat_mm mm on tt_news.uid = mm.uid_local 
  WHERE mm.uid_foreign = '.$cat." AND tt_news.deleted=0 AND tt_news.hidden=0";
		$statement = $connection->prepare($sql);
		$statement->execute();
		return $statement->fetchAll(\PDO::FETCH_ASSOC);
	}


	private function importImage($filename){
		$resource = $this->resourceManager->importResource($filename);

		$image = new Image($resource);
		$this->imageRepository->add($image);

		$processingInstructions = Array();
		return $this->objectManager->get('TYPO3\Media\Domain\Model\ImageVariant', $image, $processingInstructions);
	}

	private function importFile($filename){
		$resource = $this->resourceManager->importResource($filename);

		$asset = new Asset($resource);
		$this->assetRepository->add($asset);
		
		return $asset;
	}

}
