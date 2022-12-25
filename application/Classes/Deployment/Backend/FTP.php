<?php
namespace JetApplication;

use Jet\Debug_ErrorHandler;
use Jet\IO_Dir;
use Jet\IO_Dir_Exception;
use Jet\IO_File;
use Jet\IO_File_Exception;
use Jet\Locale;

class Deployment_Backend_FTP extends Deployment_Backend
{

	/**
	 * @var resource
	 */
	protected $connection;

	public function connect( ?string &$error_message ): bool
	{

		$connected = Debug_ErrorHandler::doItSilent(function() : bool {
			$this->connection = ftp_connect( $this->deployment->getProject()->getConnectionHost() );
			
			if($this->connection) {
				if( ftp_login(
					$this->connection,
					$this->deployment->getProject()->getConnectionUsername(),
					$this->deployment->getProject()->getConnectionPassword()
				) ) {
					if(ftp_pasv( $this->connection, true )) {
						if(ftp_chdir( $this->connection, $this->deployment->getProject()->getConnectionBasePath() )) {
							return true;
						}
					}
				}
			}
			
			return false;
		});
		
		
		if(!$connected) {
			$error = Debug_ErrorHandler::getLastError();
			
			$error_message = $error->getMessage();
			
			return false;
		}

		return true;
	}

	public function getList( $dir='.' ): array
	{
		$raw= ftp_rawlist( $this->connection, $dir, false );


		$list = array();
		foreach( $raw as $l ) {

			if(!preg_match_all('/^([drwxs+-]{10})\s+(\d+)\s+(\w+)\s+(\w+)\s+(\d+)\s+(.{12}) (.*)$/m', $l, $matches, PREG_SET_ORDER)) {
				//var_dump( $l );
				continue;
			}

			$params = $matches[0][1];
			$size = $matches[0][5];
			$name = $matches[0][7];

			if($name=='.' || $name=='..') {
				continue;
			}

			$is_dir = $params[0]=='d';

			if(!$is_dir) {
				$pi = pathinfo($name);

				if(!isset($pi['extension'])) {
					$pi['extension'] = '';
				}

				$extension = strtolower($pi['extension']);

				if(!in_array($extension, $this->deployment->getProject()->getAllowedExtensions( true ) )) {
					continue;
				}

			}

			$list[] = [
				'name' => $name,
				'is_dir' => $is_dir,
				'size' => $size
			];
		}


		return $list;
	}

	public function downloadFilesFromDir( string $ftp_dir, string $local_dir ) : bool
	{
		
		if($this->deployment->getProject()->dirIsBlacklisted($ftp_dir)) {
			return true;
		}
		
		$_ftp_dir = $this->deployment->getProject()->getConnectionBasePath().'/'.$ftp_dir;

		$this->deployment->prepareEvent('Downloading files from dir %DIR%', ['DIR'=>$_ftp_dir]);

		if($local_dir!='.') {
			try {
				IO_Dir::create( $local_dir );
			} catch( IO_Dir_Exception $e ) {
				$this->deployment->prepareError('Unable to create directory: %DIR%, %ERROR%', [
					'DIR' => $_ftp_dir,
					'ERROR' => $e->getMessage()
				]);
				
				return false;
			}
		}

		if(!Debug_ErrorHandler::doItSilent( function() use ($_ftp_dir) {
			return ftp_chdir( $this->connection, $_ftp_dir );
		} )) {
			$this->deployment->prepareError('Unable to change FTP directory: %DIR%, %ERROR%', [
				'DIR' => $_ftp_dir,
				'ERROR' => Debug_ErrorHandler::getLastError()->getMessage()
			]);
			
			return false;
		}
		
		

		$files = $this->getList();

		$dirs = array();

		foreach( $files as $d ) {
			$name = $d['name'];
			$is_dir = $d['is_dir'];
			$size = $d['size'];

			if($is_dir) {
				$dirs[] = $name;
			} else {
				
				if($this->deployment->getProject()->fileIsBlacklisted($ftp_dir.'/'.$name)) {
					return true;
				}
				
				$this->deployment->prepareEvent('Downloading file %FILE% (%SIZE%)', [
					'FILE'=>$name,
					'SIZE'=>Locale::size( $size )
				]);

				$local_path = $local_dir.$name;
				
				
				if(!Debug_ErrorHandler::doItSilent(function() use ($local_path, $name) {
					return ftp_get( $this->connection, $local_path, $name, FTP_BINARY );
				})) {
					$this->deployment->prepareError('File downloading failed. File: %FILE%, Error: %ERROR%', [
						'FILE' => $this->deployment->getProject()->getConnectionBasePath().'/'.$ftp_dir.'/'.$name,
						'ERROR' => Debug_ErrorHandler::getLastError()->getMessage()
					]);

					return false;
				}
				
				try {
					IO_File::chmod($local_path);
				} catch(IO_File_Exception $e) {
					$this->deployment->prepareError('Unable to save local file: %FILE%, Error: %ERROR%', [
						'FILE' => $local_path,
						'ERROR' => $e->getMessage()
					]);
					
					return false;
				}

			}
		}


		foreach( $dirs as $name ) {
			$next_local_dir = $local_dir.$name.'/';
			
			if(!$this->downloadFilesFromDir( $ftp_dir.$name.'/', $next_local_dir )) {
				return false;
			}

		}


		return true;
	}

	public function uploadFiles( string $local_base_dir, array $files ) : bool
	{
		foreach( $files as $file ) {
			$local_path = $local_base_dir.$file;
			$remote_path = $this->deployment->getProject()->getConnectionBasePath().'/'.$file;

			$this->deployment->deployEvent('Uploading file: %LOCAL_PATH% -> %REMOTE_PATH%', [
				'LOCAL_PATH' => $local_path,
				'REMOTE_PATH' => $remote_path
			]);

			$dirs = array();

			$dir_name = $file;

			while( ($dir_name = dirname($dir_name)) ) {
				if($dir_name=='.') {
					break;
				}

				$dirs[] = $dir_name;
			}

			if($dirs) {
				foreach( $dirs as $dir ) {
					$created = Debug_ErrorHandler::doItSilent(function() use ($dir) {
						$dir_path = $this->deployment->getProject()->getConnectionBasePath().'/'.$dir;
						
						if (ftp_nlist($this->connection, $dir_path) == false) {
							return ftp_mkdir($this->connection,$dir_path  );
						} else {
							return true;
						}
					});
					
					if(!$created) {
						$this->deployment->deployError('Unable to create target directory %DIR%, Error: %ERROR%', [
							'DIR' => $dir,
							'ERROR' => Debug_ErrorHandler::getLastError()->getMessage()
						]);
						
						return false;
					}
				}
			}
			
			$res = Debug_ErrorHandler::doItSilent(function() use ($remote_path, $local_path) {
				return ftp_put( $this->connection, $remote_path, $local_path, FTP_BINARY );
			});
			
			if(!$res) {
				$this->deployment->deployError('File uploading failed! File: %REMOTE_PATH%', [
					'REMOTE_PATH' => $remote_path
				]);
				
				return false;
			}
			
			$this->deployment->addDeployedFile( $file );
			
			$this->deployment->deployEvent('OK');
		}
		
		return true;
	}
	
	public function prepare() : bool
	{
		$this->deployment->prepareEvent('Connecting to a FTP');
		
		
		if(!$this->connect( $error_message )) {
			$this->deployment->prepareError('Unable to connect FTP: %ERROR%', [
				'ERROR' => $error_message
			]);
			
			return false;
		}
		
		$this->deployment->prepareEvent('Connected ...');
		
		
		$this->deployment->prepareEvent('Downloading started');
		$backup_dir = $this->deployment->getBackupDirPath();
		if(!$backup_dir) {
			return false;
		}
		
		if(!$this->downloadFilesFromDir(
			ftp_dir: '',
			local_dir: $backup_dir
		)) {
			return false;
		}
		
		$this->deployment->prepareEvent('Downloading done');
		
		return true;
	}
	
	public function deploy() : bool
	{
		$this->deployment->deployEvent('Connecting to a FTP');
		
		
		if(!$this->connect( $error_message )) {
			$this->deployment->deployError('Unable to connect FTP: %ERROR%', [
				'ERROR' => $error_message
			]);
			
			return false;
		}
		
		$this->deployment->deployEvent('Connected ...');

		
		return $this->uploadFiles(
			$this->deployment->getProject()->getSourceDir(),
			$this->deployment->getSelectedFiles()
		);
	}
	
}
